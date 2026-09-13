<?php

namespace App\Services\Payment;

use App\Models\Topup;

interface PaymentGateway
{
    /**
     * Buat tagihan di gateway. Yang dikembalikan adalah data yang perlu
     * ditampilkan ke user (no. VA, QR string, atau deeplink e-wallet).
     *
     * @throws PaymentGatewayException kalau gateway menolak atau tidak bisa dihubungi
     */
    public function charge(Topup $topup): ChargeResult;

    /**
     * Tanya status terkini ke gateway. Dipakai untuk rekonsiliasi kalau
     * webhook hilang, bukan untuk polling normal dari frontend.
     */
    public function fetchStatus(string $orderId): NotificationResult;

    /**
     * Verifikasi bahwa payload webhook benar-benar dari gateway, lalu
     * terjemahkan ke status internal.
     *
     * Mengembalikan null kalau signature tidak valid. Sengaja tidak melempar
     * exception supaya controller bisa balas 403 tanpa membocorkan alasan.
     */
    public function parseNotification(array $payload): ?NotificationResult;
}