<?php

namespace Tests\Support;

/**
 * Authenticator WebAuthn palsu untuk tes (pengganti Face ID / sidik jari).
 *
 * Membuat key pair ES256 (P-256) sungguhan, lalu menghasilkan respons
 * dalam format JSON yang sama persis dengan @simplewebauthn/browser:
 *   - register(options) -> RegistrationResponseJSON  (attestation "none")
 *   - assert(options)   -> AuthenticationResponseJSON
 *
 * Tidak butuh package tambahan, cukup ext-openssl.
 */
class FakeAuthenticator
{
    public string $credentialId;
    public ?string $userHandle = null;
    public int $signCount = 0;

    private \OpenSSLAsymmetricKey $key;

    public function __construct(
        private readonly string $rpId = 'localhost',
        public string $origin = 'http://localhost:5174',
    ) {
        $this->key = self::makeKey();
        $this->credentialId = random_bytes(32);
    }

    /**
     * Kunci baru per perangkat palsu kalau bisa. Di PHP Windows,
     * openssl_pkey_new() sering gagal karena openssl.cnf tidak ditemukan;
     * memuat kunci jadi (FALLBACK_KEY) tidak butuh openssl.cnf.
     *
     * Aman memakai kunci yang sama untuk beberapa perangkat palsu: yang
     * membedakan passkey di server adalah credentialId (acak per instance).
     */
    private static function makeKey(): \OpenSSLAsymmetricKey
    {
        $key = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key instanceof \OpenSSLAsymmetricKey) {
            return $key;
        }

        while (openssl_error_string() !== false) {
            // kosongkan antrean error OpenSSL supaya tidak bocor ke tes lain
        }

        return openssl_pkey_get_private(self::FALLBACK_KEY)
            ?: throw new \RuntimeException('OpenSSL tidak bisa memuat kunci tes P-256.');
    }

    /** Kunci P-256 KHUSUS TES. Jangan dipakai di kode aplikasi. */
    private const FALLBACK_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgd5dLW9c9U8DiZzi5
McCg6IaddvjlOXktw8OJX7H2Yz+hRANCAASkslZGwveMjiWkL6gduEJhISNhRggO
DsJ+elx22KnQAbRqB26Ra6hFLtdiF+4mSQLxgezYCupwJFjsDx2Z9d+p
-----END PRIVATE KEY-----
PEM;

    /**
     * @param  array  $options  hasil registrationOptions() (publicKey)
     */
    public function register(array $options): array
    {
        $this->userHandle = $options['user']['id'];

        $authData = hash('sha256', $this->rpId, true)
            . chr(0x01 | 0x04 | 0x40)           // UP | UV | AT (ada credential data)
            . pack('N', $this->signCount)
            . str_repeat("\0", 16)             // AAGUID nol
            . pack('n', strlen($this->credentialId))
            . $this->credentialId
            . $this->coseKey();

        $attestationObject = self::cborMap([
            'fmt' => 'none',
            'attStmt' => [],
            'authData' => new CborBytes($authData),
        ]);

        return [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url($this->clientData('webauthn.create', $options['challenge'])),
                'attestationObject' => self::b64url($attestationObject),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }

    /**
     * @param  array  $options  hasil loginOptions()['options']
     */
    public function assert(array $options, ?int $signCount = null): array
    {
        $this->signCount = $signCount ?? $this->signCount;

        $authData = hash('sha256', $this->rpId, true)
            . chr(0x01 | 0x04)                 // UP | UV
            . pack('N', $this->signCount);

        $clientData = $this->clientData('webauthn.get', $options['challenge']);

        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url($signature),
                'userHandle' => $this->userHandle,
            ],
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }

    public function publicKeyPem(): string
    {
        return openssl_pkey_get_details($this->key)['key'];
    }

    public static function b64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    // ------------------------------------------------------------------

    private function clientData(string $type, string $challenge): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $this->origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
    }

    /** Public key format COSE (EC2, ES256, P-256). */
    private function coseKey(): string
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];

        return self::cborMap([
            1 => 2,                                                  // kty: EC2
            3 => -7,                                                 // alg: ES256
            -1 => 1,                                                 // crv: P-256
            -2 => new CborBytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
            -3 => new CborBytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
        ]);
    }

    // ---- CBOR encoder mini (hanya tipe yang dibutuhkan WebAuthn) ----

    private static function cborMap(array $map): string
    {
        $out = self::cborHead(5, count($map));

        foreach ($map as $key => $value) {
            $out .= self::cbor($key) . self::cbor($value);
        }

        return $out;
    }

    private static function cbor(mixed $value): string
    {
        return match (true) {
            $value instanceof CborBytes => self::cborHead(2, strlen($value->bytes)) . $value->bytes,
            is_int($value) && $value >= 0 => self::cborHead(0, $value),
            is_int($value) => self::cborHead(1, -1 - $value),
            is_string($value) => self::cborHead(3, strlen($value)) . $value,
            is_array($value) => self::cborMap($value),
        };
    }

    private static function cborHead(int $major, int $length): string
    {
        $m = $major << 5;

        return match (true) {
            $length < 24 => chr($m | $length),
            $length < 0x100 => chr($m | 24) . chr($length),
            $length < 0x10000 => chr($m | 25) . pack('n', $length),
            default => chr($m | 26) . pack('N', $length),
        };
    }
}

/** Penanda "ini byte string CBOR", bukan text string. */
final class CborBytes
{
    public function __construct(public readonly string $bytes) {}
}