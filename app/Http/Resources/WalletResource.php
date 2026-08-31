<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'balance' => $this->balance,
            'balance_formatted' => 'Rp' . number_format($this->balance, 0, ',', '.'),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}