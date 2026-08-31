<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    public function topup(Wallet $wallet, int $amount, ?string $idempotencyKey = null): Transaction
    {
        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey) {
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            $before = $wallet->balance;
            $wallet->increment('balance', $amount);

            return Transaction::create([
                'wallet_id'       => $wallet->id,
                'reference_id'    => Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'type'            => 'topup',
                'direction'       => 'in',
                'amount'          => $amount,
                'balance_before'  => $before,
                'balance_after'   => $before + $amount,
                'description'     => 'Top-up saldo',
            ]);
        });
    }

    public function transfer(
        Wallet $sender,
        User $recipient,
        int $amount,
        ?string $idempotencyKey = null
    ): Transaction {
        $recipientWalletId = $recipient->wallet->id;

        return DB::transaction(function () use ($sender, $recipientWalletId, $amount, $idempotencyKey) {

            $wallets = Wallet::whereIn('id', [$sender->id, $recipientWalletId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $from = $wallets[$sender->id];
            $to   = $wallets[$recipientWalletId];

            if ($from->balance < $amount) {
                throw new InsufficientBalanceException();
            }

            $reference  = Str::uuid();
            $fromBefore = $from->balance;
            $toBefore   = $to->balance;

            $from->decrement('balance', $amount);
            $to->increment('balance', $amount);

            $out = Transaction::create([
                'wallet_id'              => $from->id,
                'counterparty_wallet_id' => $to->id,
                'reference_id'           => $reference,
                'idempotency_key'        => $idempotencyKey,
                'type'                   => 'transfer',
                'direction'              => 'out',
                'amount'                 => $amount,
                'balance_before'         => $fromBefore,
                'balance_after'          => $fromBefore - $amount,
                'description'            => 'Transfer ke '.$to->user->username,
            ]);

            Transaction::create([
                'wallet_id'              => $to->id,
                'counterparty_wallet_id' => $from->id,
                'reference_id'           => $reference,
                'type'                   => 'transfer',
                'direction'              => 'in',
                'amount'                 => $amount,
                'balance_before'         => $toBefore,
                'balance_after'          => $toBefore + $amount,
                'description'            => 'Transfer dari '.$from->user->username,
            ]);

            return $out;
        });
    }
}