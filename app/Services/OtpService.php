<?php

namespace App\Services;

use App\Exceptions\OtpException;
use App\Services\Otp\OtpSender;
use Illuminate\Support\Facades\Cache;

/**
 * Mengelola siklus hidup OTP untuk satu "sesi" (mis. satu registrasi).
 *
 * Semua state sesi disimpan dalam SATU entri cache "otp:{key}":
 *   hash         : HMAC dari kode (kode asli tidak pernah disimpan)
 *   expires_at   : kapan kode ini hangus
 *   attempts     : jumlah salah input untuk kode ini
 *   sends        : total kirim di sesi ini (lintas kode)
 *   last_sent_at : untuk cooldown kirim ulang
 *
 * issue() dan verify() dibungkus cache lock supaya dua request barengan
 * tidak bisa sama-sama lolos cooldown / sama-sama menambah attempt.
 */
class OtpService
{
    /** Umur state sesi (counter sends ikut hidup selama ini). */
    private const STATE_TTL = 3600;

    public function __construct(
        private readonly OtpSender $sender,
    ) {}

    /**
     * Buat kode baru, kirim, simpan hash-nya.
     *
     * @return array{expires_in:int, resend_in:int, sends_remaining:int, debug_otp?:string}
     *
     * @throws OtpException                                   cooldown / batas kirim
     * @throws \App\Exceptions\OtpDeliveryException           gateway gagal
     */
    public function issue(string $key, string $phone): array
    {
        return $this->locked($key, function () use ($key, $phone) {
            $cfg = config('koku.otp');
            $now = now()->getTimestamp();
            $state = Cache::get($this->cacheKey($key), ['sends' => 0, 'last_sent_at' => 0]);

            $wait = ($state['last_sent_at'] + $cfg['resend_cooldown']) - $now;
            if ($wait > 0) {
                throw OtpException::cooldown($wait);
            }

            if ($state['sends'] >= $cfg['max_sends']) {
                throw OtpException::tooManySends();
            }

            $code = $this->generateCode($cfg['length']);

            // Kirim DULU. Kalau gateway gagal, exception naik dan state tidak
            // berubah, jadi user tidak kena cooldown untuk kiriman yang gagal.
            $this->sender->send($phone, strtr($cfg['message'], [
                ':code' => $code,
                ':minutes' => (string) max(1, intdiv($cfg['ttl'], 60)),
            ]));

            $sends = $state['sends'] + 1;

            Cache::put($this->cacheKey($key), [
                'hash' => $this->hash($key, $code),
                'expires_at' => $now + $cfg['ttl'],
                'attempts' => 0,
                'sends' => $sends,
                'last_sent_at' => $now,
            ], self::STATE_TTL);

            $result = [
                'expires_in' => $cfg['ttl'],
                'resend_in' => $cfg['resend_cooldown'],
                'sends_remaining' => $cfg['max_sends'] - $sends,
            ];

            if ($cfg['expose_in_response'] && app()->environment('local', 'testing')) {
                $result['debug_otp'] = $code;
            }

            return $result;
        });
    }

    /**
     * Cek kode. Sukses = state dihapus (kode sekali pakai).
     *
     * @throws OtpException  kode salah / kedaluwarsa / terlalu banyak salah
     */
    public function verify(string $key, string $code): void
    {
        $this->locked($key, function () use ($key, $code) {
            $state = Cache::get($this->cacheKey($key));

            if (! $state || ! isset($state['hash']) || now()->getTimestamp() > $state['expires_at']) {
                throw OtpException::expired();
            }

            $max = config('koku.otp.max_attempts');

            if ($state['attempts'] >= $max) {
                throw OtpException::tooManyAttempts();
            }

            if (! hash_equals($state['hash'], $this->hash($key, $code))) {
                $state['attempts']++;
                $remaining = $max - $state['attempts'];

                if ($remaining <= 0) {
                    // Hanguskan kode, tapi pertahankan counter sends & cooldown.
                    unset($state['hash']);
                    Cache::put($this->cacheKey($key), $state, self::STATE_TTL);
                    throw OtpException::tooManyAttempts();
                }

                Cache::put($this->cacheKey($key), $state, self::STATE_TTL);
                throw OtpException::invalid($remaining);
            }

            Cache::forget($this->cacheKey($key));
        });
    }

    public function forget(string $key): void
    {
        Cache::forget($this->cacheKey($key));
    }

    // ------------------------------------------------------------------

    private function generateCode(int $length): string
    {
        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    /** HMAC diikat ke $key: kode dari sesi lain tidak bisa dipakai di sini. */
    private function hash(string $key, string $code): string
    {
        return hash_hmac('sha256', $key . '|' . $code, (string) config('app.key'));
    }

    private function cacheKey(string $key): string
    {
        return 'otp:' . $key;
    }

    private function locked(string $key, callable $callback): mixed
    {
        return Cache::lock('otp-lock:' . $key, 10)->block(5, $callback);
    }
}