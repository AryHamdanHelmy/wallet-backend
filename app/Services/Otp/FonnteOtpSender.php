<?php

namespace App\Services\Otp;

use App\Exceptions\OtpDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim OTP via WhatsApp lewat Fonnte (https://fonnte.com).
 *
 * Catatan: Fonnte membalas HTTP 200 walaupun gagal, jadi status sukses
 * harus dicek dari field "status" di body JSON, bukan dari HTTP code saja.
 */
class FonnteOtpSender implements OtpSender
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $token,
    ) {}

    public function send(string $phone, string $message): void
    {
        if (blank($this->token)) {
            Log::error('[OTP] FONNTE_TOKEN belum di-set.');
            throw new OtpDeliveryException();
        }

        try {
            $response = Http::asForm()
                ->withHeaders(['Authorization' => $this->token])
                ->timeout(10)
                ->post($this->url, [
                    'target' => $phone,   // "628…", Fonnte menerima awalan 62
                    'message' => $message,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('[OTP] Fonnte tidak bisa dihubungi: ' . $e->getMessage());
            throw new OtpDeliveryException();
        }

        if ($response->failed() || $response->json('status') !== true) {
            // Jangan log isi pesan: berisi kode OTP.
            Log::warning('[OTP] Fonnte menolak pengiriman.', [
                'http' => $response->status(),
                'reason' => $response->json('reason'),
            ]);
            throw new OtpDeliveryException();
        }
    }
}