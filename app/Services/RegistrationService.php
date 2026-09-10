<?php

namespace App\Services;

use App\Exceptions\RegistrationExpiredException;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registrasi 2 tahap:
 *   1. start()    : data disimpan sementara di cache + OTP dikirim
 *   2. complete() : OTP valid -> user dibuat (wallet otomatis via UserObserver)
 *
 * User TIDAK dibuat sebelum nomor HP terbukti milik pendaftar, jadi tidak ada
 * akun "gantung" yang mengunci Koku ID / nomor orang lain.
 */
class RegistrationService
{
    /** Maks mulai registrasi per nomor HP per jam (anti spam OTP ke nomor orang). */
    private const STARTS_PER_PHONE_PER_HOUR = 5;

    public function __construct(
        private readonly OtpService $otp,
    ) {}

    /**
     * @param  array{name:string, phone:string, username:string, pin:string}  $data
     *         phone sudah kanonik "628…", username sudah lowercase (dari FormRequest)
     */
    public function start(array $data): array
    {
        $phone = $data['phone'];
        $limiterKey = 'register-start:' . $phone;

        if (RateLimiter::tooManyAttempts($limiterKey, self::STARTS_PER_PHONE_PER_HOUR)) {
            throw new ThrottleRequestsException(
                'Terlalu banyak percobaan daftar untuk nomor ini.',
                null,
                ['Retry-After' => RateLimiter::availableIn($limiterKey)],
            );
        }
        RateLimiter::hit($limiterKey, 3600);

        // Satu nomor = satu sesi aktif. Sesi lama dibatalkan.
        if ($oldId = Cache::get($this->phoneIndexKey($phone))) {
            $this->discard($oldId, $phone);
        }

        $id = (string) Str::uuid();
        $ttl = config('koku.registration.ttl');

        Cache::put($this->sessionKey($id), [
            'name' => $data['name'],
            'phone' => $phone,
            'username' => $data['username'],
            'pin_hash' => Hash::make($data['pin']), // PIN polos tidak pernah masuk cache
        ], $ttl);
        Cache::put($this->phoneIndexKey($phone), $id, $ttl);

        try {
            $otp = $this->otp->issue($id, $phone);
        } catch (\Throwable $e) {
            $this->discard($id, $phone);
            throw $e;
        }

        return [
            'registration_id' => $id,
            'phone_masked' => PhoneNumber::mask($phone),
            'session_expires_in' => $ttl,
            ...$otp,
        ];
    }

    public function resend(string $id): array
    {
        $pending = $this->pending($id);

        return [
            'phone_masked' => PhoneNumber::mask($pending['phone']),
            ...$this->otp->issue($id, $pending['phone']),
        ];
    }

    /**
     * Ringkasan sesi untuk halaman OTP (mis. setelah user refresh browser).
     */
    public function summary(string $id): array
    {
        $pending = $this->pending($id);

        return [
            'registration_id' => $id,
            'name' => $pending['name'],
            'username' => $pending['username'],
            'phone_masked' => PhoneNumber::mask($pending['phone']),
        ];
    }

    /**
     * @throws RegistrationExpiredException
     * @throws \App\Exceptions\OtpException
     * @throws ValidationException  Koku ID / nomor keburu dipakai orang lain
     */
    public function complete(string $id, string $code): User
    {
        $pending = $this->pending($id);

        $this->otp->verify($id, $code);

        try {
            $user = DB::transaction(function () use ($pending) {
                $this->assertStillAvailable($pending);

                $user = new User();
                $user->forceFill([
                    'name' => $pending['name'],
                    'phone' => $pending['phone'],
                    'username' => $pending['username'],
                    'pin' => $pending['pin_hash'], // cast 'hashed' menerima hash bcrypt apa adanya
                    'phone_verified_at' => now(),
                ])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Race: dua pendaftar selesai di detik yang sama.
            $this->discard($id, $pending['phone']);
            throw ValidationException::withMessages([
                'username' => 'Koku ID atau nomor HP baru saja dipakai akun lain. Daftar ulang.',
            ]);
        }

        $this->discard($id, $pending['phone']);

        return $user;
    }

    // ------------------------------------------------------------------

    private function pending(string $id): array
    {
        $pending = Cache::get($this->sessionKey($id));

        if (! $pending) {
            throw new RegistrationExpiredException();
        }

        return $pending;
    }

    private function assertStillAvailable(array $pending): void
    {
        $errors = [];

        if (User::where('username', $pending['username'])->exists()) {
            $errors['username'] = 'Koku ID ini baru saja dipakai. Pilih yang lain.';
        }

        if (User::where('phone', $pending['phone'])->exists()) {
            $errors['phone'] = 'Nomor HP ini sudah terdaftar. Silakan masuk.';
        }

        if ($errors) {
            $this->discard(null, $pending['phone']);
            throw ValidationException::withMessages($errors);
        }
    }

    private function discard(?string $id, string $phone): void
    {
        $id ??= Cache::get($this->phoneIndexKey($phone));

        if ($id) {
            Cache::forget($this->sessionKey($id));
            $this->otp->forget($id);
        }

        Cache::forget($this->phoneIndexKey($phone));
    }

    private function sessionKey(string $id): string
    {
        return 'registration:' . $id;
    }

    private function phoneIndexKey(string $phone): string
    {
        return 'registration-phone:' . $phone;
    }
}