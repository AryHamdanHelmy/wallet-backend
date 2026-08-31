<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTransferTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WalletService::class);
    }

    public function test_wallet_dibuat_otomatis_saat_user_register(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->wallet);
        $this->assertSame(0, $user->wallet->balance);
    }

    public function test_topup_menambah_saldo_dan_mencatat_mutasi(): void
    {
        $user = User::factory()->create();

        $this->service->topup($user->wallet, 100_000);

        $this->assertSame(100_000, $user->wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $user->wallet->id,
            'type' => 'topup',
            'direction' => 'in',
            'amount' => 100_000,
            'balance_before' => 0,
            'balance_after' => 100_000,
        ]);
    }

    public function test_transfer_memindahkan_saldo_dan_membuat_dua_baris_ledger(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $this->service->topup($sender->wallet, 100_000);
        $this->service->transfer($sender->wallet->fresh(), $recipient, 30_000);

        $this->assertSame(70_000, $sender->wallet->fresh()->balance);
        $this->assertSame(30_000, $recipient->wallet->fresh()->balance);

        $entries = Transaction::where('type', 'transfer')->get();

        $this->assertCount(2, $entries);
        $this->assertSame(
            $entries[0]->reference_id,
            $entries[1]->reference_id,
            'Dua sisi transfer harus berbagi reference_id yang sama.'
        );
        $this->assertEqualsCanonicalizing(
            ['out', 'in'],
            $entries->pluck('direction')->all()
        );
    }

    public function test_transfer_melebihi_saldo_ditolak_dan_ter_rollback(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $this->service->topup($sender->wallet, 50_000);

        $transactionCountBefore = Transaction::count();

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->service->transfer($sender->wallet->fresh(), $recipient, 80_000);
        } finally {
            $this->assertSame(
                50_000,
                $sender->wallet->fresh()->balance,
                'Saldo pengirim tidak boleh terpotong saat transfer gagal.'
            );
            $this->assertSame(
                0,
                $recipient->wallet->fresh()->balance,
                'Saldo penerima tidak boleh bertambah saat transfer gagal.'
            );
            $this->assertSame(
                $transactionCountBefore,
                Transaction::count(),
                'Tidak boleh ada baris ledger yang tersisa setelah rollback.'
            );
        }
    }

    public function test_saldo_tidak_pernah_minus_setelah_serangkaian_transaksi(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $this->service->topup($sender->wallet, 10_000);

        foreach ([3_000, 3_000, 3_000] as $amount) {
            $this->service->transfer($sender->wallet->fresh(), $recipient, $amount);
        }

        try {
            $this->service->transfer($sender->wallet->fresh(), $recipient, 5_000);
        } catch (InsufficientBalanceException) {
            // diharapkan gagal
        }

        $this->assertSame(1_000, $sender->wallet->fresh()->balance);
        $this->assertGreaterThanOrEqual(0, $sender->wallet->fresh()->balance);
    }
}