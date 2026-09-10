<?php

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;

/**
 * Face ID / sidik jari (passkey). FakeAuthenticator membuat tanda tangan
 * kriptografi sungguhan, jadi verifikasi WebAuthn di server benar-benar diuji.
 */
class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'koku.webauthn.rp_id' => 'localhost',
            'koku.webauthn.origins' => ['http://localhost:5174'],
        ]);
    }

    private function registerPasskey(User $user, FakeAuthenticator $device)
    {
        Sanctum::actingAs($user);

        $options = $this->postJson('/api/passkeys/options')->assertOk()->json('data');

        return $this->postJson('/api/passkeys', [
            'name' => 'iPhone tes',
            'credential' => $device->register($options),
        ]);
    }

    private function loginWithPasskey(FakeAuthenticator $device, ?int $signCount = null)
    {
        $data = $this->postJson('/api/auth/passkey/options')->assertOk()->json('data');

        return $this->postJson('/api/auth/passkey/verify', [
            'challenge_id' => $data['challenge_id'],
            'credential' => $device->assert($data['options'], $signCount),
        ]);
    }

    // ------------------------------------------------------------------
    // Daftar
    // ------------------------------------------------------------------

    public function test_daftar_face_id_berhasil(): void
    {
        $user = User::factory()->create();

        $this->registerPasskey($user, new FakeAuthenticator())
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'iPhone tes')
            ->assertJsonMissingPath('data.public_key');

        $this->assertDatabaseHas('passkeys', ['user_id' => $user->id]);
        $this->getJson('/api/me')->assertJsonPath('data.has_passkey', true);
    }

    public function test_opsi_daftar_mewajibkan_biometrik_dan_passkey_tersimpan(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/passkeys/options')
            ->assertJsonPath('data.rp.id', 'localhost')
            ->assertJsonPath('data.attestation', 'none')
            ->assertJsonPath('data.authenticatorSelection.userVerification', 'required')
            ->assertJsonPath('data.authenticatorSelection.residentKey', 'required');
    }

    public function test_perangkat_yang_sama_tidak_bisa_didaftarkan_dua_kali(): void
    {
        $user = User::factory()->create();
        $device = new FakeAuthenticator();

        $this->registerPasskey($user, $device)->assertStatus(201);

        // Opsi berikutnya memberi tahu browser perangkat mana yang sudah terdaftar.
        $options = $this->postJson('/api/passkeys/options')->json('data');
        $this->assertSame(FakeAuthenticator::b64url($device->credentialId), $options['excludeCredentials'][0]['id']);

        $this->postJson('/api/passkeys', ['credential' => $device->register($options)])
            ->assertStatus(409)
            ->assertJsonPath('code', 'passkey_exists');
    }

    // ------------------------------------------------------------------
    // Masuk
    // ------------------------------------------------------------------

    public function test_masuk_dengan_face_id_tanpa_ketik_nomor(): void
    {
        $user = User::factory()->create(['username' => 'hamdan']);
        $device = new FakeAuthenticator();
        $this->registerPasskey($user, $device);

        $this->loginWithPasskey($device)
            ->assertOk()
            ->assertJsonPath('data.user.username', 'hamdan')
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        $this->assertNotNull(Passkey::first()->last_used_at);
    }

    public function test_face_id_tetap_bisa_saat_pin_terkunci(): void
    {
        $user = User::factory()->locked()->create();
        $device = new FakeAuthenticator();
        $this->registerPasskey($user, $device);

        $this->loginWithPasskey($device)->assertOk();
    }

    public function test_respons_face_id_tidak_bisa_diputar_ulang(): void
    {
        $device = new FakeAuthenticator();
        $this->registerPasskey(User::factory()->create(), $device);

        $data = $this->postJson('/api/auth/passkey/options')->json('data');
        $payload = [
            'challenge_id' => $data['challenge_id'],
            'credential' => $device->assert($data['options']),
        ];

        $this->postJson('/api/auth/passkey/verify', $payload)->assertOk();
        $this->postJson('/api/auth/passkey/verify', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'passkey_challenge_expired');
    }

    /**
     * Library WebAuthn hanya mencocokkan akhiran domain, jadi origin ini
     * LOLOS di library. Tes ini membuktikan cek ketat di PasskeyService bekerja.
     */
    public function test_origin_asing_ditolak(): void
    {
        $device = new FakeAuthenticator();
        $this->registerPasskey(User::factory()->create(), $device);

        $device->origin = 'http://evil.localhost:5174';

        $this->loginWithPasskey($device)
            ->assertStatus(422)
            ->assertJsonPath('code', 'passkey_failed')
            ->assertJsonMissingPath('data.token');
    }

    public function test_counter_mundur_dianggap_perangkat_kloning(): void
    {
        $device = new FakeAuthenticator();
        $this->registerPasskey(User::factory()->create(), $device);

        $this->loginWithPasskey($device, signCount: 5)->assertOk();
        $this->loginWithPasskey($device, signCount: 3)
            ->assertStatus(422)
            ->assertJsonPath('code', 'passkey_failed');
    }

    public function test_passkey_yang_belum_terdaftar_ditolak(): void
    {
        $this->loginWithPasskey(new FakeAuthenticator())
            ->assertStatus(422)
            ->assertJsonPath('code', 'passkey_unknown');
    }

    // ------------------------------------------------------------------
    // Kelola
    // ------------------------------------------------------------------

    public function test_tidak_bisa_menghapus_passkey_milik_orang_lain(): void
    {
        $owner = User::factory()->create();
        $this->registerPasskey($owner, new FakeAuthenticator());
        $passkey = Passkey::first();

        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson("/api/passkeys/{$passkey->id}")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/passkeys/{$passkey->id}")->assertOk();
        $this->assertDatabaseCount('passkeys', 0);
    }
}