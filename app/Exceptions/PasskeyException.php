<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kegagalan Face ID / passkey. Detail teknis (alasan dari library WebAuthn)
 * hanya masuk log, tidak dikirim ke user.
 */
class PasskeyException extends Exception
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function challengeExpired(): self
    {
        return new self('Waktu verifikasi habis. Coba lagi.', 'passkey_challenge_expired');
    }

    public static function verificationFailed(): self
    {
        return new self('Verifikasi Face ID gagal. Coba lagi atau masuk dengan PIN.', 'passkey_failed');
    }

    public static function unknownCredential(): self
    {
        return new self(
            'Face ID di perangkat ini belum terhubung ke akun Koku. Masuk dengan PIN dulu, lalu aktifkan Face ID.',
            'passkey_unknown',
        );
    }

    public static function alreadyRegistered(): self
    {
        return new self('Face ID di perangkat ini sudah aktif.', 'passkey_exists', 409);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}