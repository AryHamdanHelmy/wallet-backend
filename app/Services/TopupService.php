<?php

namespace App\Services;

use App\Enums\TopupStatus;
use App\Models\Topup;
use App\Models\User;
use App\Services\Payment\NotificationResult;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayException;
use App\Support\TopupMethods;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TopupService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly WalletService $wallet,
    ) {}

    /**
     * Buat tagihan baru. Record dibuat lebih dulu supaya kalau charge gagal
     * di tengah jalan, jejaknya tetap ada untuk ditelusuri.
     *
     * @throws PaymentGatewayException
     */
    public function create(User $user, int $amount, string $method, ?string $channel = null): Topup
    {
        $fee = TopupMethods::fee($method, $amount);

        $topup = Topup::create([
            'user_id'      => $user->id,
            'wallet_id'    => $user->wallet->id,
            'order_id'     => $this->generateOrderId(),
            'amount'       => $amount,
            'fee'          => $fee,
            'gross_amount' => $amount + $fee,
            'method'       => $method,
            'channel'      => $channel,
            'status'       => TopupStatus::Pending,
        ]);

        try {
            $result = $this->gateway->charge($topup);
        } catch (PaymentGatewayException $e) {
            $topup->update(['status' => TopupStatus::Failed]);

            throw $e;
        }

        $topup->update([
            'gateway_transaction_id' => $result->gatewayTransactionId,
            'payment_payload'        => $result->instructions,
            'expires_at'             => $result->expiresAt,
        ]);

        return $topup->refresh();
    }

    /**
     * Proses notifikasi dari gateway.
     *
     * Idempotent lewat tiga lapis: baris di-lock, status final tidak pernah
     * ditulis ulang, dan idempotency_key unik di tabel transactions jadi
     * pengaman terakhir kalau dua webhook masuk benar-benar bersamaan.
     *
     * Mengembalikan false kalau notifikasi diabaikan (order tidak dikenal atau
     * nominalnya tidak cocok), true kalau diproses.
     */
    public function handleNotification(NotificationResult $notification): bool
    {
        // Pola tuple: exception tidak dilempar dari dalam closure supaya
        // transaksi DB tidak ter-rollback hanya karena kasus yang wajar.
        [$processed, $reason] = DB::transaction(function () use ($notification) {

            $topup = Topup::where('order_id', $notification->orderId)
                ->lockForUpdate()
                ->first();

            if ($topup === null) {
                return [false, 'order_tidak_dikenal'];
            }

            if ($topup->status->isFinal()) {
                return [true, 'sudah_diproses'];
            }

            // Nominal harus sama persis dengan yang kita catat. Kalau beda,
            // payload kemungkinan dimanipulasi atau ada order_id yang bentrok.
            if ($notification->grossAmount !== null
                && $notification->grossAmount !== $topup->gross_amount) {
                return [false, 'nominal_tidak_cocok'];
            }

            if ($notification->status === TopupStatus::Pending) {
                return [true, 'masih_pending'];
            }

            if ($notification->status !== TopupStatus::Paid) {
                $topup->update([
                    'status'                 => $notification->status,
                    'gateway_transaction_id' => $notification->gatewayTransactionId
                        ?? $topup->gateway_transaction_id,
                ]);

                return [true, 'ditandai_gagal'];
            }

            $transaction = $this->wallet->topup(
                $topup->wallet,
                $topup->amount,
                idempotencyKey: 'midtrans:' . $topup->order_id,
            );

            $topup->update([
                'status'                 => TopupStatus::Paid,
                'paid_at'                => now(),
                'transaction_id'         => $transaction->id,
                'gateway_transaction_id' => $notification->gatewayTransactionId
                    ?? $topup->gateway_transaction_id,
            ]);

            return [true, 'saldo_ditambahkan'];
        });

        if (! $processed) {
            Log::warning('Notifikasi pembayaran diabaikan', [
                'order_id' => $notification->orderId,
                'alasan'   => $reason,
            ]);
        }

        return $processed;
    }

    /**
     * Dipakai saat frontend polling. Kalau sudah lewat masa berlaku tapi
     * webhook "expire" belum datang, tandai sendiri supaya user tidak
     * menunggu VA yang sudah mati.
     */
    public function refreshStatus(Topup $topup): Topup
    {
        if ($topup->isStale()) {
            $topup->update(['status' => TopupStatus::Expired]);
        }

        return $topup;
    }

    /**
     * Rekonsiliasi manual: tanya langsung ke gateway. Dipakai oleh command
     * terjadwal untuk menambal webhook yang hilang.
     */
    public function reconcile(Topup $topup): Topup
    {
        if ($topup->status->isFinal()) {
            return $topup;
        }

        $this->handleNotification($this->gateway->fetchStatus($topup->order_id));

        return $topup->refresh();
    }

    /**
     * Contoh: KOKU-20260913-A7F3K29B
     * Midtrans membatasi order_id 50 karakter dan hanya menerima huruf,
     * angka, dan beberapa simbol termasuk tanda hubung.
     */
    private function generateOrderId(): string
    {
        do {
            $orderId = 'KOKU-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (Topup::where('order_id', $orderId)->exists());

        return $orderId;
    }
}