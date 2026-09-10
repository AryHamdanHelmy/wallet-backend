<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\WalletService;
use Illuminate\Database\Seeder;

/**
 * Data demo.
 *
 *   Koku ID  | Nomor HP        | PIN
 *   hamdan   | 0812 3456 7890  | 142857
 *   aisyah   | 0812 9876 5432  | 142857
 *
 * CATATAN: sengaja TIDAK memakai trait WithoutModelEvents. Trait itu mematikan
 * UserObserver, sehingga user demo tidak mendapat wallet.
 */
class DatabaseSeeder extends Seeder
{
    public function run(WalletService $wallets): void
    {
        $hamdan = User::factory()->create([
            'name' => 'Hamdan Al-Fatih',
            'username' => 'hamdan',
            'phone' => '6281234567890',
        ]);

        $aisyah = User::factory()->create([
            'name' => 'Aisyah Putri',
            'username' => 'aisyah',
            'phone' => '6281298765432',
        ]);

        // Saldo diisi lewat WalletService (bukan update kolom balance langsung),
        // supaya riwayat transaksi & balance_before/after tetap konsisten.
        $wallets->topup($hamdan->wallet, 1_500_000);
        $wallets->topup($aisyah->wallet, 500_000);
        $wallets->transfer($hamdan->wallet->fresh(), $aisyah, 75_000, note: 'Patungan makan siang');
        $wallets->transfer($aisyah->wallet->fresh(), $hamdan, 20_000, note: 'Kembalian');

        User::factory(5)->create();
    }
}