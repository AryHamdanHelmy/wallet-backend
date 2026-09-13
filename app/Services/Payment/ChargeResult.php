<?php

namespace App\Services\Payment;

use Carbon\CarbonInterface;

/**
 * Hasil charge yang sudah dinormalkan, supaya controller dan frontend tidak
 * perlu tahu bentuk response mentah Midtrans.
 *
 * $instructions bentuknya berbeda per metode:
 *   va    => ['bank' => 'bca', 'va_number' => '12345678901']
 *   va    => ['bank' => 'mandiri', 'biller_code' => '70012', 'bill_key' => '...']
 *   qris  => ['qr_string' => '...', 'qr_url' => 'https://...']
 *   gopay => ['deeplink_url' => '...', 'qr_url' => 'https://...']
 */
final readonly class ChargeResult
{
    public function __construct(
        public string $gatewayTransactionId,
        public array $instructions,
        public ?CarbonInterface $expiresAt = null,
        public array $raw = [],
    ) {}
}