<?php

namespace App\Services\Payment;

use Exception;

class PaymentGatewayException extends Exception
{
    public function __construct(
        string $message = 'Gagal membuat pembayaran. Coba lagi sebentar lagi.',
        public readonly ?array $context = null,
    ) {
        parent::__construct($message);
    }
}