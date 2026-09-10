<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Login Nomor HP / Koku ID + PIN, lockout, dan sesi.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = UserFactory::DEFAULT_PIN;

    private function user(array $attributes = []): User
    {
        return User::factory()->create([
            'username' => 'hamdan',
            'phone' => '6281234567890',
            ...$attributes,
        ]);
    }

    private function login(string $identifier, string $pin, array $extra = [])
    {
        return $this->postJson('/api/auth/login', [
            'identifier' => $identifier,
            'pin' => $pin,
            ...$extra,
        ]);
    }

    // ------------------------------------------------------------------
    // Login berhasil
    // ------------------------------------------------------------------

    public function test_login_berhasil_dengan_nomor_hp_format_apa_pun(): void
    {
        $this->user();

        foreach (['081234567890', '+62 812-3456-7890', '6281234567890'] as $format) {
            $this->login($format, self::PIN)
                ->assertOk()
                ->assertJsonPath('data.user.username', 'hamdan')
                ->assertJsonStructure(['data' => ['user', 'token', 'token_type', 'expires_at']]);
        }
    }

    public function test_login_berhasil_dengan_koku_id_dengan_atau_tanpa_at(): void
    {
        $this->user();

        $this->login('hamdan', self::PIN)->assertOk();
        $this->login('@Hamdan', self::PIN)->assertOk();
    }

    public function test_respons_login_tidak_membocorkan_kolom_internal(): void
    {
        $this->user();

        $user = $this->login('hamdan', self::PIN)->assertOk()->json('data.user');

        $this->assertArrayNotHasKey('pin', $user);
        $this->assertArrayNotHasKey('pin_failed_attempts', $user);
        $this->assertArrayNotHasKey('pin_locked_until', $user);
        $this->assertSame('+62 812 3456 7890', $user['phone_display']);
        $this->assertFalse($user['has_passkey']);
    }

    // ------------------------------------------------------------------
    // PIN salah & lockout
    // ------------------------------------------------------------------

    public function test_pin_salah_menampilkan_sisa_percobaan_lalu_terkunci(): void
    {
        $this->user();

        $this->login('hamdan', '000001')
            ->assertStatus(422)
            ->assertJsonPath('code', 'pin_invalid')
            ->assertJsonPath('attempts_remaining', 2)
            ->assertJsonPath('errors.pin.0', 'Nomor/Koku ID atau PIN salah (tersisa 2 kali).');

        $this->login('hamdan', '000002')->assertJsonPath('attempts_remaining', 1);

        $this->login('hamdan', '000003')
            ->assertStatus(423)
            ->assertJsonPath('code', 'pin_locked')
            ->assertJsonPath('retry_after', 15 * 60);
    }

    /**
     * Penjaga bug rollback: kalau exception dilempar di dalam DB::transaction,
     * counter salah PIN ikut dibatalkan dan akun tidak pernah terkunci.
     */
    public function test_akun_terkunci_menolak_pin_yang_benar(): void
    {
        $user = $this->user();

        foreach (['000001', '000002', '000003'] as $wrong) {
            $this->login('hamdan', $wrong);
        }

        $this->assertNotNull($user->fresh()->pin_locked_until, 'Lockout harus tersimpan di database.');

        $this->login('hamdan', self::PIN)
            ->assertStatus(423)
            ->assertJsonMissingPath('data.token');
    }

    public function test_akun_terbuka_lagi_setelah_masa_kunci_habis(): void
    {
        $user = $this->user(['pin_locked_until' => now()->addMinutes(15)]);

        $this->travel(16)->minutes();

        $this->login('hamdan', self::PIN)->assertOk();
        $this->assertNull($user->fresh()->pin_locked_until);
    }

    public function test_login_berhasil_mereset_counter_salah_pin(): void
    {
        $user = $this->user();

        $this->login('hamdan', '000001');
        $this->login('hamdan', '000002');
        $this->assertSame(2, $user->fresh()->pin_failed_attempts);

        $this->login('hamdan', self::PIN)->assertOk();
        $this->assertSame(0, $user->fresh()->pin_failed_attempts);

        // Setelah reset, jatah salah kembali penuh.
        $this->login('hamdan', '000003')->assertJsonPath('attempts_remaining', 2);
    }

    public function test_respons_akun_tidak_terdaftar_identik_dengan_akun_asli(): void
    {
        $this->user();

        foreach ([1, 2, 3] as $attempt) {
            $real = $this->login('hamdan', '000001');
            $ghost = $this->login('tidakada', '000001');

            $this->assertSame($real->status(), $ghost->status(), "Status beda di percobaan ke-{$attempt}.");
            $this->assertSame(
                $real->json(),
                $ghost->json(),
                "Isi respons beda di percobaan ke-{$attempt}: akun terdaftar bisa ditebak."
            );
        }
    }

    // ------------------------------------------------------------------
    // Sesi
    // ------------------------------------------------------------------

    public function test_ingat_perangkat_menentukan_umur_token(): void
    {
        $this->user();

        $this->login('hamdan', self::PIN, ['remember' => true])->assertOk();
        $this->login('hamdan', self::PIN, ['remember' => false])->assertOk();

        [$long, $short] = PersonalAccessToken::orderBy('id')->get()->all();

        $this->assertEqualsWithDelta(30 * 24, now()->diffInHours($long->expires_at), 1);
        $this->assertEqualsWithDelta(24, now()->diffInHours($short->expires_at), 1);
    }

    public function test_me_mengembalikan_user_dan_wallet(): void
    {
        $this->user();
        $token = $this->login('hamdan', self::PIN)->json('data.token');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'hamdan')
            ->assertJsonPath('data.wallet.balance', 0)
            ->assertJsonMissingPath('data.pin');
    }

    public function test_logout_mencabut_token(): void
    {
        $this->user();
        $token = $this->login('hamdan', self::PIN)->json('data.token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertSame(0, PersonalAccessToken::count());
    }
}