<?php

namespace Tests\Feature;

use App\Exceptions\OtpDeliveryException;
use App\Models\User;
use App\Services\Otp\OtpSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registrasi 2 langkah: data pribadi -> OTP -> akun aktif.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'name' => 'Budi Santoso',
        'phone' => '0813 1111 2222',
        'username' => 'budi',
        'pin' => '280619',
        'terms' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Kode OTP ikut di response (field debug_otp), hanya aktif di env testing.
        config(['koku.otp.expose_in_response' => true]);
    }

    /** @return array{0:string,1:string}  [registration_id, otp] */
    private function start(array $override = []): array
    {
        $data = $this->postJson('/api/auth/register', [...$this->payload, ...$override])
            ->assertStatus(202)
            ->json('data');

        return [$data['registration_id'], $data['debug_otp']];
    }

    private function verify(string $id, string $otp)
    {
        return $this->postJson('/api/auth/register/verify', [
            'registration_id' => $id,
            'otp' => $otp,
        ]);
    }

    // ------------------------------------------------------------------
    // Alur utama
    // ------------------------------------------------------------------

    public function test_langkah_1_mengirim_otp_tanpa_membuat_user(): void
    {
        $this->postJson('/api/auth/register', $this->payload)
            ->assertStatus(202)
            ->assertJsonPath('data.phone_masked', '+62 813-****-2222')
            ->assertJsonStructure(['data' => ['registration_id', 'expires_in', 'resend_in', 'debug_otp']]);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_otp_benar_membuat_akun_wallet_dan_token(): void
    {
        [$id, $otp] = $this->start();

        $this->verify($id, $otp)
            ->assertStatus(201)
            ->assertJsonPath('data.user.username', 'budi')
            ->assertJsonPath('data.user.phone', '6281311112222')
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        $user = User::firstWhere('username', 'budi');
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNotNull($user->wallet);

        // PIN dari langkah 1 langsung bisa dipakai login.
        $this->postJson('/api/auth/login', ['identifier' => 'budi', 'pin' => '280619'])->assertOk();
    }

    public function test_sesi_registrasi_tidak_bisa_dipakai_dua_kali(): void
    {
        [$id, $otp] = $this->start();

        $this->verify($id, $otp)->assertStatus(201);
        $this->verify($id, $otp)->assertStatus(410)->assertJsonPath('code', 'registration_expired');
    }

    // ------------------------------------------------------------------
    // OTP
    // ------------------------------------------------------------------

    public function test_otp_salah_menampilkan_sisa_percobaan(): void
    {
        [$id, $otp] = $this->start();
        $wrong = $otp === '000000' ? '111111' : '000000';

        $this->verify($id, $wrong)
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_invalid')
            ->assertJsonPath('attempts_remaining', 4)
            ->assertJsonPath('errors.otp.0', 'Kode salah (tersisa 4 kali).');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_kode_hangus_setelah_lima_kali_salah(): void
    {
        [$id, $otp] = $this->start();
        $wrong = $otp === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->verify($id, $wrong)->assertJsonPath('code', 'otp_invalid');
        }
        $this->verify($id, $wrong)->assertJsonPath('code', 'otp_attempts_exceeded');

        // Kode yang benar pun sudah tidak berlaku.
        $this->verify($id, $otp)->assertJsonPath('code', 'otp_expired');
    }

    public function test_kirim_ulang_harus_menunggu_cooldown(): void
    {
        [$id] = $this->start();

        $this->postJson('/api/auth/register/resend', ['registration_id' => $id])
            ->assertStatus(429)
            ->assertJsonPath('code', 'otp_cooldown');

        $this->travel(61)->seconds();

        $newOtp = $this->postJson('/api/auth/register/resend', ['registration_id' => $id])
            ->assertOk()
            ->json('data.debug_otp');

        $this->verify($id, $newOtp)->assertStatus(201);
    }

    public function test_kode_kedaluwarsa_setelah_5_menit(): void
    {
        [$id, $otp] = $this->start();

        $this->travel(301)->seconds();

        $this->verify($id, $otp)->assertStatus(422)->assertJsonPath('code', 'otp_expired');
    }

    public function test_sesi_habis_setelah_10_menit(): void
    {
        [$id, $otp] = $this->start();

        $this->travel(11)->minutes();

        $this->verify($id, $otp)->assertStatus(410)->assertJsonPath('code', 'registration_expired');
    }

    public function test_gagal_kirim_otp_dibalas_503_dan_tidak_meninggalkan_sesi(): void
    {
        $this->app->bind(OtpSender::class, fn () => new class implements OtpSender {
            public function send(string $phone, string $message): void
            {
                throw new OtpDeliveryException();
            }
        });

        $this->postJson('/api/auth/register', $this->payload)->assertStatus(503);

        $this->assertNull(cache('registration-phone:6281311112222'));
    }

    // ------------------------------------------------------------------
    // Validasi langkah 1
    // ------------------------------------------------------------------

    public function test_data_langkah_1_divalidasi(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'B',
            'phone' => '021 555 666',
            'username' => '1budi',
            'pin' => '123456',
            'terms' => false,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'phone', 'username', 'pin', 'terms']);
    }

    public function test_pin_lemah_ditolak(): void
    {
        foreach (['111111', '654321', '121212'] as $pin) {
            $this->postJson('/api/auth/register', [...$this->payload, 'pin' => $pin])
                ->assertJsonValidationErrors('pin');
        }
    }

    public function test_koku_id_terlarang_ditolak(): void
    {
        $this->postJson('/api/auth/register', [...$this->payload, 'username' => 'Admin'])
            ->assertJsonPath('errors.username.0', 'Koku ID ini tidak bisa dipakai.');
    }

    public function test_nomor_yang_sudah_terdaftar_ditolak_walau_formatnya_beda(): void
    {
        User::factory()->create(['phone' => '6281311112222']);

        $this->postJson('/api/auth/register', [...$this->payload, 'phone' => '+62 813-1111-2222'])
            ->assertJsonPath('errors.phone.0', 'Nomor HP ini sudah terdaftar. Silakan masuk.');
    }

    public function test_koku_id_keburu_dipakai_orang_lain_saat_verifikasi(): void
    {
        [$id, $otp] = $this->start();

        User::factory()->create(['username' => 'budi']);

        $this->verify($id, $otp)
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');

        $this->assertDatabaseCount('users', 1);
    }

    // ------------------------------------------------------------------
    // Cek ketersediaan Koku ID
    // ------------------------------------------------------------------

    public function test_cek_ketersediaan_koku_id(): void
    {
        User::factory()->create(['username' => 'hamdan']);

        $taken = $this->getJson('/api/auth/username-availability?username=hamdan')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->json('data.suggestions');

        $this->assertNotEmpty($taken);
        $this->assertNotContains('hamdan', $taken);

        $this->getJson('/api/auth/username-availability?username=@Budi_01')
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.username', 'budi_01')
            ->assertJsonPath('data.message', 'Tersedia');

        $this->getJson('/api/auth/username-availability?username=admin')
            ->assertJsonPath('data.available', false);
    }

    public function test_ringkasan_sesi_untuk_halaman_otp(): void
    {
        [$id] = $this->start();

        $this->getJson("/api/auth/register/{$id}")
            ->assertOk()
            ->assertJsonPath('data.phone_masked', '+62 813-****-2222')
            ->assertJsonMissingPath('data.pin_hash');
    }
}