<?php

namespace App\Enums;

enum TopupStatus: string
{
    case Pending = 'pending';
    case Paid    = 'paid';
    case Expired = 'expired';
    case Failed  = 'failed';

    /** Status akhir: tidak boleh berubah lagi, termasuk oleh webhook susulan. */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu pembayaran',
            self::Paid    => 'Berhasil',
            self::Expired => 'Kedaluwarsa',
            self::Failed  => 'Gagal',
        };
    }
}