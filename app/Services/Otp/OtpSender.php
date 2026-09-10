<?php

namespace App\Services\Otp;

use App\Exceptions\OtpDeliveryException;

/**
 * Kontrak pengiriman OTP. OtpService tidak peduli OTP lewat log, WhatsApp,
 * atau SMS; cukup panggil send(). Driver dipilih di config('koku.otp.driver').
 */
interface OtpSender
{
    /**
     * @param  string  $phone    Nomor format kanonik "628…"
     * @param  string  $message  Pesan final yang sudah berisi kode
     *
     * @throws OtpDeliveryException  kalau pesan gagal terkirim
     */
    public function send(string $phone, string $message): void;
}