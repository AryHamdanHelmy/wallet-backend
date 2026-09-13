<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TopupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_id'     => $this->order_id,
            'amount'       => $this->amount,
            'fee'          => $this->fee,
            'gross_amount' => $this->gross_amount,
            'method'       => $this->method,
            'channel'      => $this->channel,
            'status'       => $this->status->value,
            'status_label' => $this->status->label(),

            // Nomor VA / QR string / deeplink. Bentuknya beda per metode,
            // frontend memilih tampilan berdasarkan field "method".
            'payment'      => $this->payment_payload,

            'expires_at'   => $this->expires_at?->toIso8601String(),
            'paid_at'      => $this->paid_at?->toIso8601String(),
            'created_at'   => $this->created_at->toIso8601String(),
        ];
    }
}