<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_wallet_ditolak_tanpa_token(): void
    {
        $this->getJson('/api/wallet')->assertStatus(401);
        $this->getJson('/api/transactions')->assertStatus(401);
        $this->postJson('/api/topup', ['amount' => 10000])->assertStatus(401);
        $this->postJson('/api/transfer', ['recipient' => 'a@b.com', 'amount' => 10000])
            ->assertStatus(401);
    }

    public function test_user_bisa_melihat_saldonya_sendiri(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance', 0);
    }

    public function test_topup_menambah_saldo(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/topup', ['amount' => 100000])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertSame(100000, $user->wallet->fresh()->balance);
    }

    public function test_topup_menolak_nominal_berupa_huruf(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/topup', ['amount' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'Nominal harus berupa angka.');

        $this->assertSame(0, $user->wallet->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_topup_menolak_nominal_kosong(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/topup', ['amount' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'Nominal tidak boleh kosong.');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_topup_menolak_nominal_bersimbol(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/topup', ['amount' => '@#$'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_topup_menolak_nominal_negatif(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/topup', ['amount' => -5000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, $user->wallet->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_topup_menolak_nominal_melebihi_batas_maksimum(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/topup', [
            'amount' => config('wallet.max_transaction') + 1,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount.0' ,'Nominal melebihi batas maksimum transaksi.');
    }

    public function test_transfer_berhasil_ke_penerima_via_email(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'budi@gmail.com']);

        app(WalletService::class)->topup($sender->wallet, 100000);
        Sanctum::actingAs($sender);

        $this->postJson('/api/transfer', [
            'recipient' => 'budi@gmail.com',
            'amount' => 30000,
        ])->assertStatus(201);

        $this->assertSame(70000, $sender->wallet->fresh()->balance);
        $this->assertSame(30000, $recipient->wallet->fresh()->balance);
    }

    public function test_transfer_berhasil_ke_penerima_via_nomor_hp(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['phone' => '6281298765432']);

        app(WalletService::class)->topup($sender->wallet, 100000);
        Sanctum::actingAs($sender);

        $this->postJson('/api/transfer', [
            'recipient' => '081298765432',
            'amount' => 25000,
        ])->assertStatus(201);

        $this->assertSame(25000, $recipient->wallet->fresh()->balance);
    }

    public function test_transfer_melebihi_saldo_mengembalikan_400(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'budi@gmail.com']);

        app(WalletService::class)->topup($sender->wallet, 10000);
        Sanctum::actingAs($sender);

        $this->postJson('/api/transfer', [
            'recipient' => 'budi@gmail.com',
            'amount' => 50000,
        ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Saldo tidak mencukupi.');

        $this->assertSame(10000, $sender->wallet->fresh()->balance);
        $this->assertSame(0, $recipient->wallet->fresh()->balance);
    }

    public function test_transfer_ke_diri_sendiri_ditolak(): void
    {
        $user = User::factory()->create(['email' => 'ary@gmail.com']);
        app(WalletService::class)->topup($user->wallet, 100000);
        Sanctum::actingAs($user);

        $this->postJson('/api/transfer', [
            'recipient' => 'ary@gmail.com',
            'amount' => 10000,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('recipient');
    }

    public function test_user_hanya_melihat_mutasinya_sendiri(): void
    {
        $ary = User::factory()->create();
        $budi = User::factory()->create();
        $orangLain = User::factory()->create();

        $svc = app(WalletService::class);
        $svc->topup($ary->wallet, 100000);
        $svc->transfer($ary->wallet->fresh(), $budi, 30000);
        $svc->topup($orangLain->wallet, 500000);

        Sanctum::actingAs($ary);

        $response = $this->getJson('/api/transactions')->assertOk();

        $walletIds = collect($response->json('data.data'))->pluck('id');

        $this->assertCount(2, $walletIds, 'Ary hanya punya 2 mutasi: topup dan transfer keluar.');

        $response->assertJsonMissing(['amount' => 500000]);
    }
}