<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'wallet_id', 'counterparty_wallet_id', 'reference_id',
        'idempotency_key', 'type', 'direction',
        'amount', 'balance_before', 'balance_after', 'description',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'integer',
            'balance_before' => 'integer',
            'balance_after'  => 'integer',
        ];
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function counterparty()
    {
        return $this->belongsTo(Wallet::class, 'counterparty_wallet_id');
    }
}
