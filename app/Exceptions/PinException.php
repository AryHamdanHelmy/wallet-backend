<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kegagalan login PIN. Pesan sengaja tidak menyebut "nomor tidak terdaftar"
 * supaya tidak bisa dipakai menebak akun mana yang ada.
 */
class PinException extends Exception
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
        private readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function invalid(int $attemptsRemaining): self
    {
        return new self(
            "Nomor/Koku ID atau PIN salah (tersisa {$attemptsRemaining} kali).",
            'pin_invalid', 422, ['attempts_remaining' => $attemptsRemaining],
        );
    }

    public static function locked(int $seconds): self
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return new self(
            "Terlalu banyak PIN salah. Coba lagi dalam {$minutes} menit, atau masuk dengan Face ID.",
            'pin_locked', 423, ['retry_after' => $seconds],
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
            'errors' => ['pin' => [$this->getMessage()]],
            ...$this->extra,
        ], $this->status);
    }
}