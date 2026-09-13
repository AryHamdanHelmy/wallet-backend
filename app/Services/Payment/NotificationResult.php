<?php

namespace App\Services\Payment;

use App\Enums\TopupStatus;

final readonly class NotificationResult
{
    public function __construct(
        public string $orderId,
        public TopupStatus $status,
        public ?string $gatewayTransactionId = null,
        public ?int $grossAmount = null,
        public array $raw = [],
    ) {}
}