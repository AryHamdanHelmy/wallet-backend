<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Semua kegagalan OTP yang disebabkan user (bukan server).
 *
 * Response selalu membawa "code" (string mesin) supaya frontend bisa
 * bereaksi tanpa parsing pesan, mis. otp_expired -> tampilkan "Kirim ulang".
 */
class OtpException extends Exception
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
        private readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function cooldown(int $seconds): self
    {
        return new self(
            "Tunggu {$seconds} detik sebelum kirim ulang kode.",
            'otp_cooldown', 429, ['retry_after' => $seconds],
        );
    }

    public static function tooManySends(): self
    {
        return new self(
            'Batas kirim ulang kode tercapai. Mulai pendaftaran dari awal.',
            'otp_send_limit', 429,
        );
    }

    public static function expired(): self
    {
        return new self(
            'Kode sudah kedaluwarsa. Minta kode baru.',
            'otp_expired', 422,
        );
    }

    public static function invalid(int $attemptsRemaining): self
    {
        return new self(
            "Kode salah (tersisa {$attemptsRemaining} kali).",
            'otp_invalid', 422, ['attempts_remaining' => $attemptsRemaining],
        );
    }

    public static function tooManyAttempts(): self
    {
        return new self(
            'Terlalu banyak kode salah. Minta kode baru.',
            'otp_attempts_exceeded', 422,
        );
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
            // Format sama dengan ValidationException, jadi getFieldErrors()
            // di frontend otomatis menempelkan pesan ini ke input OTP.
            'errors' => ['otp' => [$this->getMessage()]],
            ...$this->extra,
        ], $this->status);
    }
}