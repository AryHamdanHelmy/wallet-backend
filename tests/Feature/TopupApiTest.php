<?php

namespace Tests\Feature;

use App\Enums\TopupStatus;
use App\Models\Topup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TopupApiTest extends TestCase
{
    use RefreshDatabase;

    /** Response sukses Midtrans untuk VA bank (bca/bni/bri). */
    private function fakeVaCharge(string $bank = 'bca', int $grossAmount = 54_000): void
    {
        Http::fake([
            '*/v2/charge' => Http::response([
                'status_code'        => '201',
                'status_message'     => 'Success, Bank Transfer transaction is created',
                'transaction_id'     => 'trx-abc-123',
                'order_id'           => 'diisi-oleh-request',
                'gross_amount'       => number_format($grossAmount, 2, '.', ''),
                'payment_type'       => 'bank_transfer',
                'transaction_status' => 'pending',
                'fraud_status'       => 'accept',
                'va_numbers'         => [['bank' => $bank, 'va_number' => '12345678901']],
                'expiry_time'        => now()->addHour()->format('Y-m-d H:i:s'),
            ]),
        ]);
    }

    // ------------------------------------------------------------------
    // Membuat tagihan
    // ------------------------------------------------------------------

    public function test_membuat_tagihan_belum_menambah_saldo(): void
    {
        $this->fakeVaCharge();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/topups', [
            'amount'  => 50_000,
            'method'  => 'va',
            'channel' => 'bca',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 50_000)
            ->assertJsonPath('data.payment.va_number', '12345678901');

        // Ini inti keamanannya: uang belum masuk sebelum benar-benar dibayar.
        $this->assertSame(0, $user->wallet->refresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_biaya_admin_dibebankan_di_atas_nominal(): void
    {
        $this->fakeVaCharge();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/topups', [
            'amount'  => 50_000,
            'method'  => 'va',
            'channel' => 'bca',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.amount', 50_000)
            ->assertJsonPath('data.fee', 4_000)
            ->assertJsonPath('data.gross_amount', 54_000);

        // Yang ditagihkan ke Midtrans harus nominal + fee.
        Http::assertSent(function ($request) {
            return $request['transaction_details']['gross_amount'] === 54_000;
        });
    }

    public function test_qris_memakai_payment_type_yang_benar(): void
    {
        Http::fake([
            '*/v2/charge' => Http::response([
                'status_code'        => '201',
                'transaction_id'     => 'trx-qris-1',
                'transaction_status' => 'pending',
                'payment_type'       => 'qris',
                'qr_string'          => '000201010212...',
                'actions'            => [
                    ['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/xxx/qr-code'],
                ],
                'expiry_time'        => now()->addHour()->format('Y-m-d H:i:s'),
            ]),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/topups', ['amount' => 100_000, 'method' => 'qris'])
            ->assertStatus(201)
            ->assertJsonPath('data.method', 'qris')
            ->assertJsonPath('data.fee', 700) // 0,7% dari 100.000
            ->assertJsonPath('data.payment.qr_string', '000201010212...');

        Http::assertSent(fn ($request) => $request['payment_type'] === 'qris');
    }

    public function test_mandiri_memakai_echannel_bukan_bank_transfer(): void
    {
        Http::fake([
            '*/v2/charge' => Http::response([
                'status_code'        => '201',
                'transaction_id'     => 'trx-mandiri-1',
                'transaction_status' => 'pending',
                'payment_type'       => 'echannel',
                'biller_code'        => '70012',
                'bill_key'           => '990000000123',
                'expiry_time'        => now()->addHour()->format('Y-m-d H:i:s'),
            ]),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/topups', [
            'amount'  => 50_000,
            'method'  => 'va',
            'channel' => 'mandiri',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.payment.biller_code', '70012')
            ->assertJsonPath('data.payment.bill_key', '990000000123');

        Http::assertSent(fn ($request) => $request['payment_type'] === 'echannel');
    }

    public function test_permata_membaca_nomor_va_dari_key_tersendiri(): void
    {
        Http::fake([
            '*/v2/charge' => Http::response([
                'status_code'        => '201',
                'transaction_id'     => 'trx-permata-1',
                'transaction_status' => 'pending',
                'payment_type'       => 'bank_transfer',
                'permata_va_number'  => '8562000000000001',
                'expiry_time'        => now()->addHour()->format('Y-m-d H:i:s'),
            ]),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/topups', [
            'amount'  => 50_000,
            'method'  => 'va',
            'channel' => 'permata',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.payment.va_number', '8562000000000001');
    }

    // ------------------------------------------------------------------
    // Validasi
    // ------------------------------------------------------------------

    public function test_metode_tidak_dikenal_ditolak(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/topups', ['amount' => 50_000, 'method' => 'bitcoin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');

        Http::assertNothingSent();
    }

    public function test_va_tanpa_memilih_bank_ditolak(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/topups', ['amount' => 50_000, 'method' => 'va'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');

        Http::assertNothingSent();
    }

    public function test_nominal_di_bawah_minimum_ditolak(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/topups', [
            'amount'  => 5_000,
            'method'  => 'va',
            'channel' => 'bca',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        Http::assertNothingSent();
    }

    public function test_endpoint_topup_ditolak_tanpa_token(): void
    {
        $this->postJson('/api/topups', ['amount' => 50_000, 'method' => 'qris'])
            ->assertStatus(401);

        $this->getJson('/api/topups')->assertStatus(401);
        $this->getJson('/api/topups/methods')->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // Gateway bermasalah
    // ------------------------------------------------------------------

    public function test_gateway_menolak_membuat_tagihan(): void
    {
        Http::fake([
            '*/v2/charge' => Http::response([
                'status_code'    => '402',
                'status_message' => 'Payment channel is not activated.',
            ], 402),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/topups', [
            'amount'  => 50_000,
            'method'  => 'va',
            'channel' => 'bca',
        ])->assertStatus(503);

        // Record tetap tersimpan untuk ditelusuri, tapi ditandai gagal
        // supaya tidak nyangkut sebagai "menunggu pembayaran".
        $this->assertSame(TopupStatus::Failed, Topup::first()->status);
        $this->assertSame(0, $user->wallet->refresh()->balance);
    }

    public function test_gateway_error_server_dibalas_503(): void
    {
        Http::fake(['*/v2/charge' => Http::response('', 500)]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/topups', [
            'amount'  => 50_000,
            'method'  => 'va',
            'channel' => 'bca',
        ])->assertStatus(503);
    }

    // ------------------------------------------------------------------
    // Melihat status & riwayat
    // ------------------------------------------------------------------

    public function test_user_tidak_bisa_melihat_topup_milik_orang_lain(): void
    {
        $orangLain = User::factory()->create();
        $topup = Topup::factory()->forUser($orangLain)->create();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/topups/' . $topup->order_id)->assertStatus(404);
    }

    public function test_topup_yang_lewat_masa_berlaku_ditandai_kedaluwarsa_saat_dicek(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Masih pending di DB karena webhook "expire" belum sampai.
        $topup = Topup::factory()->forUser($user)->stale()->create();

        $this->getJson('/api/topups/' . $topup->order_id)
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertSame(TopupStatus::Expired, $topup->refresh()->status);
    }

    public function test_riwayat_hanya_menampilkan_topup_sendiri(): void
    {
        $ary = User::factory()->create();
        $orangLain = User::factory()->create();

        Topup::factory()->forUser($ary)->count(3)->create();
        Topup::factory()->forUser($orangLain)->count(2)->create();

        Sanctum::actingAs($ary);

        $this->getJson('/api/topups')
            ->assertOk()
            ->assertJsonCount(3, 'data.data');
    }

    public function test_daftar_metode_menghitung_fee_sesuai_nominal(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/topups/methods?amount=100000')->assertOk();

        $methods = collect($response->json('data.methods'))->keyBy('key');

        $this->assertSame(4_000, $methods['va']['fee']);
        $this->assertSame(104_000, $methods['va']['total']);
        $this->assertSame(700, $methods['qris']['fee']);   // 0,7%
        $this->assertSame(2_000, $methods['gopay']['fee']); // 2%
    }

    public function test_metode_yang_dinonaktifkan_tidak_muncul(): void
    {
        config()->set('midtrans.methods.shopeepay.enabled', false);

        Sanctum::actingAs(User::factory()->create());

        $keys = collect($this->getJson('/api/topups/methods')->json('data.methods'))
            ->pluck('key');

        $this->assertNotContains('shopeepay', $keys);
        $this->assertContains('qris', $keys);
    }
}