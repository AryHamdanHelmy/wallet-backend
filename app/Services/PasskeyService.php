<?php

namespace App\Services;

use App\Exceptions\PasskeyException;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

/**
 * Face ID / Touch ID / sidik jari lewat WebAuthn (passkey).
 *
 * Alur daftar (user sudah login):
 *   registrationOptions() -> browser navigator.credentials.create() -> register()
 *
 * Alur masuk (tamu):
 *   loginOptions() -> browser navigator.credentials.get() -> authenticate()
 *
 * Semua data biner (challenge, credential id) disimpan sebagai base64url,
 * karena biner mentah bisa rusak di kolom teks cache database MySQL.
 */
class PasskeyService
{
    // ------------------------------------------------------------------
    // Daftarkan passkey
    // ------------------------------------------------------------------

    /**
     * Opsi untuk navigator.credentials.create(), format JSON base64url
     * (langsung bisa dipakai @simplewebauthn/browser startRegistration).
     */
    public function registrationOptions(User $user): array
    {
        $server = $this->server();

        // Perangkat yang sudah terdaftar tidak ditawari daftar ulang.
        $exclude = $user->passkeys()
            ->pluck('credential_id')
            ->map(fn (string $id) => ByteBuffer::fromBase64Url($id))
            ->all();

        $args = $server->getCreateArgs(
            $this->userHandle($user),
            $user->username,
            $user->name,
            config('koku.webauthn.timeout'),
            true,   // resident key: wajib, supaya bisa login tanpa ketik nomor
            true,   // user verification: wajib biometrik/PIN perangkat
            null,   // semua jenis authenticator (bawaan HP/laptop, HP sebagai kunci, security key)
            $exclude,
        );

        $args->publicKey->attestation = 'none';
        unset($args->publicKey->extensions);

        Cache::put(
            $this->registrationKey($user),
            $this->b64url($server->getChallenge()->getBinaryString()),
            config('koku.webauthn.challenge_ttl'),
        );

        return $this->toArray($args->publicKey);
    }

    /**
     * @param  array  $credential  RegistrationResponseJSON dari browser
     *
     * @throws PasskeyException
     */
    public function register(User $user, array $credential, string $name): Passkey
    {
        // pull = ambil lalu hapus: challenge hanya bisa dipakai sekali.
        $challenge = Cache::pull($this->registrationKey($user))
            ?? throw PasskeyException::challengeExpired();

        $clientDataJSON = $this->decode($credential['response']['clientDataJSON'] ?? null);
        $this->assertAllowedOrigin($clientDataJSON);

        try {
            $data = $this->server()->processCreate(
                $clientDataJSON,
                $this->decode($credential['response']['attestationObject'] ?? null),
                ByteBuffer::fromBase64Url($challenge),
                true,   // wajib user verification
                true,   // wajib user presence
                false,  // tidak memvalidasi sertifikat root authenticator
            );
        } catch (WebAuthnException $e) {
            Log::info('[Passkey] daftar gagal: ' . $e->getMessage(), ['user_id' => $user->id]);
            throw PasskeyException::verificationFailed();
        }

        $credentialId = $this->b64url($data->credentialId);

        if (Passkey::where('credential_id', $credentialId)->exists()) {
            throw PasskeyException::alreadyRegistered();
        }

        return $user->passkeys()->create([
            'name' => $name,
            'credential_id' => $credentialId,
            'public_key' => $data->credentialPublicKey,
            'sign_count' => (int) ($data->signatureCounter ?? 0),
            'aaguid' => $this->formatAaguid($data->AAGUID),
            'backup_eligible' => (bool) $data->isBackupEligible,
            'backed_up' => (bool) $data->isBackedUp,
        ]);
    }

    // ------------------------------------------------------------------
    // Masuk dengan passkey
    // ------------------------------------------------------------------

    /**
     * Opsi untuk navigator.credentials.get(). allowCredentials dikosongkan:
     * perangkat sendiri yang menawarkan passkey Koku yang tersimpan,
     * jadi user tidak perlu mengetik nomor HP dulu.
     *
     * @return array{challenge_id:string, options:array}
     */
    public function loginOptions(): array
    {
        $server = $this->server();

        $args = $server->getGetArgs(
            [],
            config('koku.webauthn.timeout'),
            true, true, true, true, true,
            true, // wajib user verification
        );

        $challengeId = (string) Str::uuid();

        Cache::put(
            $this->loginKey($challengeId),
            $this->b64url($server->getChallenge()->getBinaryString()),
            config('koku.webauthn.challenge_ttl'),
        );

        return [
            'challenge_id' => $challengeId,
            'options' => $this->toArray($args->publicKey),
        ];
    }

    /**
     * @param  array  $credential  AuthenticationResponseJSON dari browser
     *
     * @throws PasskeyException
     */
    public function authenticate(string $challengeId, array $credential): User
    {
        $challenge = Cache::pull($this->loginKey($challengeId))
            ?? throw PasskeyException::challengeExpired();

        $credentialId = (string) ($credential['rawId'] ?? $credential['id'] ?? '');

        $passkey = Passkey::with('user')->where('credential_id', $credentialId)->first()
            ?? throw PasskeyException::unknownCredential();

        // Spesifikasi WebAuthn langkah 2: userHandle harus milik pemilik passkey.
        $userHandle = $credential['response']['userHandle'] ?? null;
        if ($userHandle && ! hash_equals($this->b64url($this->userHandle($passkey->user)), $userHandle)) {
            throw PasskeyException::verificationFailed();
        }

        $clientDataJSON = $this->decode($credential['response']['clientDataJSON'] ?? null);
        $this->assertAllowedOrigin($clientDataJSON);

        $server = $this->server();

        try {
            $server->processGet(
                $clientDataJSON,
                $this->decode($credential['response']['authenticatorData'] ?? null),
                $this->decode($credential['response']['signature'] ?? null),
                $passkey->public_key,
                ByteBuffer::fromBase64Url($challenge),
                $passkey->sign_count,  // deteksi authenticator hasil kloning
                true,                  // wajib user verification
            );
        } catch (WebAuthnException $e) {
            Log::warning('[Passkey] masuk gagal: ' . $e->getMessage(), ['passkey_id' => $passkey->id]);
            throw PasskeyException::verificationFailed();
        }

        $passkey->forceFill([
            'sign_count' => $server->getSignatureCounter() ?? $passkey->sign_count,
            'last_used_at' => now(),
        ])->save();

        return $passkey->user;
    }

    // ------------------------------------------------------------------

    private function server(): WebAuthn
    {
        // Argumen ke-4 true: ByteBuffer di-JSON-kan sebagai base64url.
        return new WebAuthn(
            config('koku.webauthn.rp_name'),
            config('koku.webauthn.rp_id'),
            null,
            true,
        );
    }

    /**
     * ID user untuk authenticator: 32 byte acak-tapi-tetap, tanpa data pribadi
     * (spesifikasi melarang email/nomor HP di sini) dan tanpa kolom tambahan.
     */
    private function userHandle(User $user): string
    {
        return hash_hmac('sha256', 'passkey-user:' . $user->id, (string) config('app.key'), true);
    }

    /**
     * Cek ketat: origin harus persis salah satu WEBAUTHN_ORIGINS.
     * Pengecekan bawaan library hanya mencocokkan akhiran domain.
     */
    private function assertAllowedOrigin(string $clientDataJSON): void
    {
        $origin = json_decode($clientDataJSON)->origin ?? null;

        if (! in_array($origin, config('koku.webauthn.origins'), true)) {
            Log::warning('[Passkey] origin ditolak: ' . var_export($origin, true));
            throw PasskeyException::verificationFailed();
        }
    }

    private function decode(mixed $base64url): string
    {
        if (! is_string($base64url) || $base64url === '') {
            throw PasskeyException::verificationFailed();
        }

        return ByteBuffer::fromBase64Url($base64url)->getBinaryString();
    }

    private function b64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /** ByteBuffer -> string base64url lewat jsonSerialize(). */
    private function toArray(object $publicKey): array
    {
        return json_decode(json_encode($publicKey), true);
    }

    /** 16 byte biner -> "xxxxxxxx-xxxx-…"; AAGUID nol (attestation none) -> null. */
    private function formatAaguid(?string $binary): ?string
    {
        if (! $binary || trim($binary, "\0") === '') {
            return null;
        }

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($binary), 4));
    }

    private function registrationKey(User $user): string
    {
        return 'passkey-reg:' . $user->id;
    }

    private function loginKey(string $challengeId): string
    {
        return 'passkey-login:' . $challengeId;
    }
}