<?php

namespace App\Models;

use App\Enums\TopupStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Topup extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id', 'wallet_id', 'order_id',
        'amount', 'fee', 'gross_amount',
        'method', 'channel', 'status',
        'gateway_transaction_id', 'payment_payload',
        'transaction_id', 'expires_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'          => 'integer',
            'fee'             => 'integer',
            'gross_amount'    => 'integer',
            'status'          => TopupStatus::class,
            'payment_payload' => 'array',
            'expires_at'      => 'datetime',
            'paid_at'         => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    /** Mutasi ledger yang dibuat setelah pembayaran lunas. */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isPending(): bool
    {
        return $this->status === TopupStatus::Pending;
    }

    /**
     * Sudah lewat expires_at tapi status masih pending. Dipakai saat polling
     * supaya user tidak menunggu VA yang sudah mati, tanpa harus menunggu
     * webhook "expire" dari Midtrans yang kadang telat.
     */
    public function isStale(): bool
    {
        return $this->isPending()
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}