<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_id' => $this->reference_id,
            'type' => $this->type,
            'direction' => $this->direction,
            'amount' => $this->amount,
            'amount_formatted' => ($this->direction === 'out' ? '-' : '+')
                . 'Rp' . number_format($this->amount, 0, ',', '.'),
            'balance_after' => $this->balance_after,
            'counterparty' => $this->when(
                $this->counterparty_wallet_id !== null,
                fn () => [
                    'name' => $this->counterparty?->user?->name,
                    'username' => $this->counterparty?->user?->username,
                ]
            ),
            'description' => $this->description,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}