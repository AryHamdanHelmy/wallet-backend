<?php

namespace Tests\Feature;

use App\Enums\TopupStatus;
use App\Models\Topup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopupWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-KUNCI-PALSU-UNTUK-TES';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('midtrans.server_key', self::SERVER_KEY);
    }

    /**
     * Payload webhook lengkap dengan signature yang benar.
     *
     * gross_amount dikirim Midtrans sebagai string 2 desimal ("54000.00").
     * Signature harus dihitung dari string itu apa adanya.
     */
    private function payload(Topup $topup, string $status = 'settlement', array $override = []): array
    {
        $payload = array_merge([
            'order_id'           => $topup->order_id,
            'status_code'        => $status === 'settlement' ? '200' : '201',
            'gross_amount'       => number_format($topup->gross_amount, 2, '.', ''),
            'transaction_status' => $status,
            'transaction_id'     => 'trx-' . fake()->uuid(),
            'payment_type'       => 'bank_transfer',
        ], $override);

        $payload['signature_key'] = hash('sha512',
            $payload['order_id']
            . $payload['status_code']
            . $payload['gross_amount']
            . self::SERVER_KEY
        );

        return $payload;
    }

    private function send(array $payload)
    {
        return $this->postJson('/api/midtrans/notification', $payload);
    }

    // ------------------------------------------------------------------

    public function test_pembayaran_lunas_menambah_saldo_sebesar_nominal_tanpa_fee(): void
    {
        $user = User::factory()->create();

        $topup = Topup::factory()->forUser($user)->create([
            'amount'       => 50_000,
            'fee'          => 4_000,
            'gross_amount' => 54_000,
        ]);

        $this->send($this->payload($topup))->assertOk();

        // Saldo bertambah 50.000, bukan 54.000. Fee adalah biaya, bukan saldo.
        $this->assertSame(50_000, $user->wallet->refresh()->balance);

        $topup->refresh();
        $this->assertSame(TopupStatus::Paid, $topup->status);
        $this->assertNotNull($topup->paid_at);
        $this->assertNotNull($topup->transaction_id);

        $this->assertDatabaseHas('transactions', [
            'wallet_id'       => $user->wallet->id,
            'type'            => 'topup',
            'direction'       => 'in',
            'amount'          => 50_000,
            'balance_after'   => 50_000,
            'idempotency_key' => 'midtrans:' . $topup->order_id,
        ]);
    }

    public function test_signature_palsu_ditolak_dan_saldo_tidak_berubah(): void
    {
        $user = User::factory()->create();
        $topup = Topup::factory()->forUser($user)->create(['amount' => 50_000]);

        $payload = $this->payload($topup);
        $payload['signature_key'] = str_repeat('a', 128);

        $this->send($payload)->assertStatus(403);

        $this->assertSame(0, $user->wallet->refresh()->balance);
        $this->assertSame(TopupStatus::Pending, $topup->refresh()->status);
    }

    public function test_webhook_tanpa_signature_ditolak(): void
    {
        $topup = Topup::factory()->create();

        $payload = $this->payload($topup);
        unset($payload['signature_key']);

        $this->send($payload)->assertStatus(403);
        $this->assertSame(TopupStatus::Pending, $topup->refresh()->status);
    }

    public function test_webhook_dikirim_dua_kali_hanya_menambah_saldo_sekali(): void
    {
        $user = User::factory()->create();

        $topup = Topup::factory()->forUser($user)->create([
            'amount'       => 100_000,
            'fee'          => 4_000,
            'gross_amount' => 104_000,
        ]);

        $payload = $this->payload($topup);

        $this->send($payload)->assertOk();
        $this->send($payload)->assertOk(); // Midtrans retry
        $this->send($payload)->assertOk(); // dan sekali lagi

        $this->assertSame(100_000, $user->wallet->refresh()->balance);
        $this->assertSame(1, $user->wallet->transactions()->where('type', 'topup')->count());
    }

    public function test_nominal_yang_tidak_cocok_diabaikan(): void
    {
        $user = User::factory()->create();

        $topup = Topup::factory()->forUser($user)->create([
            'amount'       => 50_000,
            'gross_amount' => 54_000,
        ]);

        // Penyerang mengaku membayar 10 juta, dengan signature yang valid
        // untuk nominal karangannya sendiri.
        $payload = $this->payload($topup, override: ['gross_amount' => '10000000.00']);

        $this->send($payload)->assertOk();

        $this->assertSame(0, $user->wallet->refresh()->balance);
        $this->assertSame(TopupStatus::Pending, $topup->refresh()->status);
    }

    public function test_pembayaran_kedaluwarsa_menandai_topup_tanpa_menyentuh_saldo(): void
    {
        $user = User::factory()->create();
        $topup = Topup::factory()->forUser($user)->create(['amount' => 50_000]);

        $this->send($this->payload($topup, 'expire'))->assertOk();

        $this->assertSame(0, $user->wallet->refresh()->balance);
        $this->assertSame(TopupStatus::Expired, $topup->refresh()->status);
    }

    public function test_pembayaran_dibatalkan_ditandai_gagal(): void
    {
        $user = User::factory()->create();
        $topup = Topup::factory()->forUser($user)->create(['amount' => 50_000]);

        $this->send($this->payload($topup, 'cancel'))->assertOk();

        $this->assertSame(0, $user->wallet->refresh()->balance);
        $this->assertSame(TopupStatus::Failed, $topup->refresh()->status);
    }

    public function test_notifikasi_pending_tidak_mengubah_apa_pun(): void
    {
        $user = User::factory()->create();
        $topup = Topup::factory()->forUser($user)->create(['amount' => 50_000]);

        $this->send($this->payload($topup, 'pending'))->assertOk();

        $this->assertSame(0, $user->wallet->refresh()->balance);
        $this->assertSame(TopupStatus::Pending, $topup->refresh()->status);
    }

    public function test_order_yang_tidak_dikenal_dibalas_200_bukan_error(): void
    {
        // Dibalas 200 supaya Midtrans berhenti mengirim ulang notifikasi
        // untuk order yang memang tidak ada di sistem kita.
        $topup = Topup::factory()->make(['order_id' => 'KOKU-20260913-TIDAKADA']);

        $this->send($this->payload($topup))->assertOk();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_topup_yang_sudah_lunas_tidak_bisa_diubah_jadi_gagal(): void
    {
        $user = User::factory()->create();

        $topup = Topup::factory()->forUser($user)->paid()->create([
            'amount'       => 50_000,
            'gross_amount' => 54_000,
        ]);

        // Notifikasi susulan yang datang terlambat tidak boleh membatalkan
        // pembayaran yang sudah final.
        $this->send($this->payload($topup, 'expire'))->assertOk();

        $this->assertSame(TopupStatus::Paid, $topup->refresh()->status);
    }

    public function test_webhook_tidak_butuh_login(): void
    {
        $topup = Topup::factory()->create();

        // Tanpa Sanctum::actingAs sama sekali.
        $this->send($this->payload($topup))->assertOk();

        $this->assertSame(TopupStatus::Paid, $topup->refresh()->status);
    }
}