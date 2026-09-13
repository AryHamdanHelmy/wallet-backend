<?php

namespace App\Services\Payment;

use App\Enums\TopupStatus;
use App\Models\Topup;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $serverKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 15,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Charge
    |--------------------------------------------------------------------------
    */

    public function charge(Topup $topup): ChargeResult
    {
        $body = array_merge(
            [
                'transaction_details' => [
                    'order_id'     => $topup->order_id,
                    'gross_amount' => $topup->gross_amount,
                ],
                'item_details' => [
                    [
                        'id'       => 'topup',
                        'name'     => 'Top-up saldo Koku',
                        'price'    => $topup->amount,
                        'quantity' => 1,
                    ],
                    [
                        'id'       => 'fee',
                        'name'     => 'Biaya admin',
                        'price'    => $topup->fee,
                        'quantity' => 1,
                    ],
                ],
                'customer_details' => [
                    'first_name' => $topup->user->name,
                    'phone'      => $topup->user->phone,
                ],
                'custom_expiry' => [
                    // Midtrans minta waktu lokal merchant beserta offset.
                    'order_time'      => now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s O'),
                    'expiry_duration' => (int) config('midtrans.expiry_minutes'),
                    'unit'            => 'minute',
                ],
            ],
            $this->paymentTypePayload($topup),
        );

        // item_details dengan harga 0 ditolak Midtrans, jadi buang kalau tanpa fee.
        $body['item_details'] = array_values(array_filter(
            $body['item_details'],
            fn (array $item) => $item['price'] > 0,
        ));

        $response = $this->request('post', '/v2/charge', $body);

        $data = $response->json();

        // "201" = pending (menunggu pembayaran), "200" = langsung sukses.
        if (! in_array($data['status_code'] ?? null, ['200', '201'], true)) {
            throw new PaymentGatewayException(
                'Gagal membuat pembayaran. Coba metode lain atau ulangi sebentar lagi.',
                context: $data,
            );
        }

        return new ChargeResult(
            gatewayTransactionId: $data['transaction_id'],
            instructions: $this->extractInstructions($topup, $data),
            expiresAt: isset($data['expiry_time'])
                ? Carbon::parse($data['expiry_time'], 'Asia/Jakarta')
                : now()->addMinutes((int) config('midtrans.expiry_minutes')),
            raw: $data,
        );
    }

    /**
     * Bagian payload yang berbeda per metode pembayaran.
     * Mandiri sengaja dipisah karena di Core API namanya "echannel",
     * bukan "bank_transfer" seperti VA lainnya.
     */
    private function paymentTypePayload(Topup $topup): array
    {
        return match ($topup->method) {
            'va' => $topup->channel === 'mandiri'
                ? [
                    'payment_type' => 'echannel',
                    'echannel' => [
                        'bill_info1' => 'Top-up Koku',
                        'bill_info2' => $topup->order_id,
                    ],
                ]
                : [
                    'payment_type' => 'bank_transfer',
                    'bank_transfer' => ['bank' => $topup->channel],
                ],

            'qris' => [
                'payment_type' => 'qris',
                'qris' => ['acquirer' => 'gopay'],
            ],

            'gopay' => [
                'payment_type' => 'gopay',
                'gopay' => ['enable_callback' => false],
            ],

            'shopeepay' => [
                'payment_type' => 'shopeepay',
            ],

            default => throw new PaymentGatewayException(
                'Metode pembayaran tidak dikenali.',
            ),
        };
    }

    /**
     * Ambil hanya data yang perlu ditampilkan ke user. Bentuk response
     * Midtrans beda-beda per metode, jadi dinormalkan di sini.
     */
    private function extractInstructions(Topup $topup, array $data): array
    {
        if ($topup->method === 'va') {
            // Mandiri (echannel)
            if (isset($data['biller_code'], $data['bill_key'])) {
                return [
                    'bank'        => 'mandiri',
                    'biller_code' => $data['biller_code'],
                    'bill_key'    => $data['bill_key'],
                ];
            }

            // Permata dikembalikan di key tersendiri, bukan di va_numbers.
            if (isset($data['permata_va_number'])) {
                return [
                    'bank'      => 'permata',
                    'va_number' => $data['permata_va_number'],
                ];
            }

            $va = $data['va_numbers'][0] ?? null;

            if ($va === null) {
                throw new PaymentGatewayException(
                    'Nomor virtual account tidak diterima dari gateway.',
                    context: $data,
                );
            }

            return [
                'bank'      => $va['bank'],
                'va_number' => $va['va_number'],
            ];
        }

        $actions = collect($data['actions'] ?? [])->keyBy('name');

        return array_filter([
            'qr_string'    => $data['qr_string'] ?? null,
            'qr_url'       => $actions['generate-qr-code']['url'] ?? null,
            'deeplink_url' => $actions['deeplink-redirect']['url'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /*
    |--------------------------------------------------------------------------
    | Status & notifikasi
    |--------------------------------------------------------------------------
    */

    public function fetchStatus(string $orderId): NotificationResult
    {
        $data = $this->request('get', "/v2/{$orderId}/status")->json();

        return $this->toNotificationResult($data);
    }

    public function parseNotification(array $payload): ?NotificationResult
    {
        if (! $this->signatureIsValid($payload)) {
            return null;
        }

        return $this->toNotificationResult($payload);
    }

    /**
     * Signature Midtrans: sha512(order_id + status_code + gross_amount + server_key).
     * gross_amount harus dipakai persis seperti yang dikirim ("10000.00"),
     * jangan di-cast ke integer dulu atau hash-nya tidak akan cocok.
     */
    private function signatureIsValid(array $payload): bool
    {
        $signature = $payload['signature_key'] ?? null;

        if (! is_string($signature)) {
            return false;
        }

        $expected = hash('sha512',
            ($payload['order_id'] ?? '')
            . ($payload['status_code'] ?? '')
            . ($payload['gross_amount'] ?? '')
            . $this->serverKey
        );

        return hash_equals($expected, $signature);
    }

    private function toNotificationResult(array $data): NotificationResult
    {
        return new NotificationResult(
            orderId: $data['order_id'],
            status: $this->mapStatus($data),
            gatewayTransactionId: $data['transaction_id'] ?? null,
            grossAmount: isset($data['gross_amount'])
                ? (int) round((float) $data['gross_amount'])
                : null,
            raw: $data,
        );
    }

    private function mapStatus(array $data): TopupStatus
    {
        $status = $data['transaction_status'] ?? null;

        // "capture" hanya muncul untuk kartu kredit. Kalau fraud_status masih
        // "challenge", dana belum pasti cair, jadi jangan dianggap lunas.
        if ($status === 'capture') {
            return ($data['fraud_status'] ?? null) === 'accept'
                ? TopupStatus::Paid
                : TopupStatus::Pending;
        }

        return match ($status) {
            'settlement' => TopupStatus::Paid,
            'pending'    => TopupStatus::Pending,
            'expire'     => TopupStatus::Expired,
            'deny', 'cancel', 'failure', 'refund', 'partial_refund' => TopupStatus::Failed,
            default      => TopupStatus::Pending,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    private function request(string $method, string $path, array $body = []): Response
    {
        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->{$method}($this->baseUrl . $path, $body);
        } catch (ConnectionException $e) {
            // Sengaja tidak di-retry: charge yang sebenarnya sudah sampai ke
            // Midtrans akan ditolak di percobaan kedua karena order_id duplikat.
            // Lebih aman user menekan tombol lagi dan dapat order_id baru.
            Log::warning('Midtrans tidak dapat dihubungi', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            throw new PaymentGatewayException(
                'Layanan pembayaran sedang tidak dapat dihubungi. Coba lagi sebentar lagi.',
            );
        }

        if ($response->failed() && $response->status() >= 500) {
            Log::error('Midtrans mengembalikan error server', [
                'path'   => $path,
                'status' => $response->status(),
            ]);

            throw new PaymentGatewayException(
                'Layanan pembayaran sedang bermasalah. Coba lagi sebentar lagi.',
            );
        }

        // 4xx tetap diteruskan: body-nya berisi status_code Midtrans yang
        // lebih informatif dan diperiksa oleh pemanggil.
        return $response;
    }
}