<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Menolak PIN yang gampang ditebak ("Harus angka acak" di mock).
 *
 * Ditolak:
 *   - semua digit sama          : 111111, 000000
 *   - urutan naik / turun       : 123456, 345678, 987654
 *   - pola berulang             : 121212, 123123
 *   - bagian dari nomor HP sendiri
 */
class StrongPin implements ValidationRule
{
    public function __construct(
        private readonly ?string $phone = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pin = (string) $value;

        if (! preg_match('/^\d{6}$/', $pin)) {
            return; // format ditangani rule digits:6
        }

        if (count(array_unique(str_split($pin))) === 1) {
            $fail('PIN tidak boleh angka yang sama semua.');
            return;
        }

        if (str_contains('0123456789', $pin) || str_contains('9876543210', $pin)) {
            $fail('PIN tidak boleh angka berurutan.');
            return;
        }

        if (preg_match('/^(\d{2})\1\1$/', $pin) || preg_match('/^(\d{3})\1$/', $pin)) {
            $fail('PIN tidak boleh pola berulang seperti 121212.');
            return;
        }

        if ($this->phone && str_contains($this->phone, $pin)) {
            $fail('PIN tidak boleh bagian dari nomor HP kamu.');
        }
    }
}