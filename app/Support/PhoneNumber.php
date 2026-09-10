<?php

namespace App\Support;

/**
 * Helper nomor HP Indonesia.
 *
 * Format kanonik yang disimpan di database: "628xxxxxxxxx" (E.164 tanpa "+").
 *
 * Semua variasi input berikut dianggap nomor yang sama:
 *   "081234567890", "81234567890", "6281234567890",
 *   "+62 812-3456-7890", "(0812) 3456 7890"
 */
final class PhoneNumber
{
    /** 62 + 8 + 8..12 digit  →  setara dengan 08xxxxxxxx s/d 08xxxxxxxxxxxx */
    private const CANONICAL_PATTERN = '/^628\d{8,12}$/';

    /**
     * Ubah input bebas ke format kanonik "628…".
     * Return null kalau hasilnya bukan nomor HP Indonesia yang valid.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input);

        if ($digits === '' || $digits === null) {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }

        return preg_match(self::CANONICAL_PATTERN, $digits) ? $digits : null;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /**
     * Tebakan cepat: apakah identifier login ini nomor HP (bukan Koku ID)?
     * Koku ID wajib diawali huruf, jadi input yang diawali angka atau "+"
     * pasti dimaksudkan sebagai nomor HP.
     */
    public static function looksLikePhone(string $input): bool
    {
        return (bool) preg_match('/^\s*[+\d(]/', $input);
    }

    /**
     * Untuk ditampilkan: "6281234567890" → "+62 812 3456 7890".
     */
    public static function format(string $canonical): string
    {
        $local = substr($canonical, 2); // buang "62"

        return '+62 ' . trim(implode(' ', [
            substr($local, 0, 3),
            substr($local, 3, 4),
            substr($local, 7),
        ]));
    }

    /**
     * Untuk info "kode dikirim ke …": "6281234567890" → "+62 812-****-7890".
     */
    public static function mask(string $canonical): string
    {
        $local = substr($canonical, 2);

        return '+62 ' . substr($local, 0, 3) . '-****-' . substr($local, -4);
    }

    /**
     * Format lokal, mis. untuk gateway yang minta awalan 0: "081234567890".
     */
    public static function toLocal(string $canonical): string
    {
        return '0' . substr($canonical, 2);
    }
}