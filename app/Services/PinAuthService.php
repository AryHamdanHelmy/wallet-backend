<?php

namespace App\Services;

use App\Exceptions\PinException;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Login dengan Nomor HP / Koku ID + PIN 6 digit.
 *
 * Lockout per akun: salah PIN sebanyak koku.pin.max_attempts
 * -> akun dikunci koku.pin.lock_minutes.
 *
 * Anti-enumerasi: identifier yang tidak terdaftar mendapat counter "bayangan"
 * di cache, sehingga pesan, sisa percobaan, lockout, dan waktu respons
 * identik dengan akun yang benar-benar ada.
 */
class PinAuthService
{
    /**
     * @throws PinException
     */
    public function attempt(string $identifier, string $pin): User
    {
        $user = $this->resolveUser($identifier);

        if (! $user) {
            $this->failGhost($identifier);
        }

        // PENTING: transaction hanya MENGEMBALIKAN hasil. Exception dilempar
        // setelah commit; kalau dilempar di dalam closure, update counter
        // salah PIN ikut ter-rollback dan lockout tidak pernah terjadi.
        [$outcome, $value] = DB::transaction(function () use ($user, $pin) {
            // Kunci baris user: dua tebakan paralel tidak bisa sama-sama
            // lolos sebelum counter bertambah.
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($user->pin_locked_until?->isFuture()) {
                return ['locked', (int) now()->diffInSeconds($user->pin_locked_until, true)];
            }

            if (! Hash::check($pin, $user->pin)) {
                return $this->recordFailure($user);
            }

            if ($user->pin_failed_attempts > 0 || $user->pin_locked_until) {
                $user->forceFill([
                    'pin_failed_attempts' => 0,
                    'pin_locked_until' => null,
                ])->save();
            }

            return ['ok', $user];
        });

        return match ($outcome) {
            'ok' => $value,
            'locked' => throw PinException::locked($value),
            'invalid' => throw PinException::invalid($value),
        };
    }

    /**
     * Cari user dari nomor HP (format apa pun) atau Koku ID (boleh pakai "@").
     */
    public function resolveUser(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if (PhoneNumber::looksLikePhone($identifier)) {
            $phone = PhoneNumber::normalize($identifier);

            return $phone ? User::where('phone', $phone)->first() : null;
        }

        return User::where('username', mb_strtolower(ltrim($identifier, '@')))->first();
    }

    // ------------------------------------------------------------------

    /**
     * @return array{0:'locked'|'invalid', 1:int}  [hasil, detik terkunci | sisa percobaan]
     */
    private function recordFailure(User $user): array
    {
        $max = config('koku.pin.max_attempts');
        $attempts = $user->pin_failed_attempts + 1;

        if ($attempts >= $max) {
            $seconds = config('koku.pin.lock_minutes') * 60;

            $user->forceFill([
                'pin_failed_attempts' => 0,
                'pin_locked_until' => now()->addSeconds($seconds),
            ])->save();

            return ['locked', $seconds];
        }

        $user->forceFill(['pin_failed_attempts' => $attempts])->save();

        return ['invalid', $max - $attempts];
    }

    private function failGhost(string $identifier): never
    {
        // Samakan waktu respons dengan jalur Hash::check yang asli.
        Hash::check('000000', $this->dummyHash());

        $key = 'pin-ghost:' . hash('sha256', mb_strtolower(trim($identifier)));
        $lockSeconds = config('koku.pin.lock_minutes') * 60;
        $max = config('koku.pin.max_attempts');
        $now = now()->getTimestamp();
        $state = Cache::get($key, ['attempts' => 0, 'locked_until' => 0]);

        if ($state['locked_until'] > $now) {
            throw PinException::locked($state['locked_until'] - $now);
        }

        $attempts = $state['attempts'] + 1;

        if ($attempts >= $max) {
            Cache::put($key, ['attempts' => 0, 'locked_until' => $now + $lockSeconds], $lockSeconds);
            throw PinException::locked($lockSeconds);
        }

        Cache::put($key, ['attempts' => $attempts, 'locked_until' => 0], $lockSeconds);
        throw PinException::invalid($max - $attempts);
    }

    private function dummyHash(): string
    {
        return Cache::rememberForever('pin-dummy-hash', fn () => Hash::make('dummy-pin'));
    }
}