<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi untuk semua pergerakan saldo: top-up, transfer keluar,
 * dan transfer masuk.
 *
 * Satu class untuk tiga skenario, karena bentuk datanya identik dan yang
 * berbeda cuma kalimatnya. Kalau nanti ada jenis notifikasi yang beda
 * total (mis. keamanan / promo), bikin class sendiri — jangan ditambal
 * ke sini dengan if baru.
 *
 * Catatan: SENGAJA tidak implements ShouldQueue. Channel "database" cuma
 * insert satu baris, dan kalau di-queue sementara worker mati, notifikasi
 * tidak pernah muncul di bell. Saat channel WhatsApp ditambahkan nanti,
 * pindahkan pengiriman WA-nya ke job terpisah, bukan class ini.
 */
class TransactionNotification extends Notification
{
    /**
     * @param  Transaction  $transaction  Transaksi milik wallet si penerima
     *                                    notifikasi (bukan lawan transaksinya).
     */
    public function __construct(
        public readonly Transaction $transaction,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Payload ini yang dibaca frontend. Kolom "event" dipakai untuk memilih
     * ikon di bell, jadi nilainya harus stabil — kalau diubah, ikonnya
     * jadi fallback.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $trx = $this->transaction;
        $counterparty = $trx->counterparty?->user;

        return [
            'event' => $this->event(),
            'title' => $this->title(),
            'body' => $this->body($counterparty?->name),
            'amount' => $trx->amount,
            'amount_formatted' => $this->rupiah($trx->amount),
            'direction' => $trx->direction,
            'transaction_id' => $trx->id,
            'reference_id' => $trx->reference_id,
            'counterparty' => $counterparty ? [
                'name' => $counterparty->name,
                'username' => $counterparty->username,
            ] : null,
        ];
    }

    /** topup | transfer_in | transfer_out */
    private function event(): string
    {
        if ($this->transaction->type === 'topup') {
            return 'topup';
        }

        return $this->transaction->direction === 'in'
            ? 'transfer_in'
            : 'transfer_out';
    }

    private function title(): string
    {
        return match ($this->event()) {
            'topup' => 'Top up berhasil',
            'transfer_in' => 'Saldo masuk',
            default => 'Transfer terkirim',
        };
    }

    private function body(?string $counterpartyName): string
    {
        $amount = $this->rupiah($this->transaction->amount);
        $name = $counterpartyName ?? 'pengguna Koku';

        return match ($this->event()) {
            'topup' => "Saldo kamu bertambah {$amount}.",
            'transfer_in' => "Kamu menerima {$amount} dari {$name}.",
            default => "{$amount} berhasil dikirim ke {$name}.",
        };
    }

    private function rupiah(int $amount): string
    {
        return 'Rp' . number_format($amount, 0, ',', '.');
    }
}