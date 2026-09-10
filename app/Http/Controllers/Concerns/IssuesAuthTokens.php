<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dipakai AuthController (login PIN, registrasi) dan PasskeyController
 * (login Face ID), supaya semua jalur masuk menghasilkan token & bentuk
 * data user yang sama persis.
 */
trait IssuesAuthTokens
{
    /** Umur token: "Ingat perangkat ini" dicentang vs tidak. */
    protected int $rememberDays = 30;
    protected int $sessionHours = 24;

    /**
     * @return array{token:string, token_type:string, expires_at:string}
     */
    protected function issueToken(User $user, Request $request, bool $remember): array
    {
        $expiresAt = $remember
            ? now()->addDays($this->rememberDays)
            : now()->addHours($this->sessionHours);

        $deviceName = $request->input('device_name')
            ?: Str::limit((string) ($request->userAgent() ?: 'koku-web'), 60, '');

        return [
            'token' => $user->createToken($deviceName, ['*'], $expiresAt)->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Bentuk data user yang boleh keluar ke frontend. Eksplisit, supaya
     * kolom internal (pin, counter lockout) tidak ikut bocor.
     */
    protected function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'phone' => $user->phone,
            'phone_display' => $user->phone ? PhoneNumber::format($user->phone) : null,
            'email' => $user->email,
            'has_passkey' => $user->passkeys()->exists(),
        ];
    }
}