<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kurs untuk kartu berjalan di dashboard.
 *
 * Satu panggilan ke penyedia melayani SEMUA user selama masa cache,
 * karena kurs tidak bergantung pada siapa yang login. Jangan pernah
 * memasukkan user id ke dalam cache key di sini.
 *
 * Dua lapis cache yang dipakai:
 *   fx.rates.{BASE}        - hasil segar, kedaluwarsa mengikuti TTL
 *   fx.rates.stale.{BASE}  - salinan terakhir, tanpa kedaluwarsa
 *
 * Lapis kedua itu yang bikin kartu kurs tidak pernah kosong. Kalau
 * penyedia sedang mati, user tetap melihat angka terakhir dengan
 * penanda is_stale, bukan layar error — angka kemarin jauh lebih
 * berguna daripada tidak ada angka sama sekali.
 */
class FxRateService
{
    private const TTL = 60 * 60 * 6; // 6 jam

    /**
     * Mata uang yang tampil di dashboard, berikut nama dan benderanya.
     * Sengaja dibatasi: penyedia mengirim 160+ mata uang, dan mengirim
     * semuanya ke frontend cuma memperbesar payload tanpa dipakai.
     *
     * @var array<string, array{name: string, flag: string}>
     */
    private const CURRENCIES = [
        'USD' => ['name' => 'Dolar Amerika', 'flag' => '🇺🇸'],
        'EUR' => ['name' => 'Euro', 'flag' => '🇪🇺'],
        'SGD' => ['name' => 'Dolar Singapura', 'flag' => '🇸🇬'],
        'MYR' => ['name' => 'Ringgit Malaysia', 'flag' => '🇲🇾'],
        'JPY' => ['name' => 'Yen Jepang', 'flag' => '🇯🇵'],
        'SAR' => ['name' => 'Riyal Saudi', 'flag' => '🇸🇦'],
        'AUD' => ['name' => 'Dolar Australia', 'flag' => '🇦🇺'],
        'GBP' => ['name' => 'Poundsterling', 'flag' => '🇬🇧'],
        'CNY' => ['name' => 'Yuan Tiongkok', 'flag' => '🇨🇳'],
        'THB' => ['name' => 'Baht Thailand', 'flag' => '🇹🇭'],
    ];

    /**
     * @return array{base: string, rates: array<int, array<string, mixed>>, updated_at: ?string, next_update_at: ?string, is_stale: bool, source: string, attribution: string, eol_at: ?string}
     */
    public function latest(string $base = 'IDR'): array
    {
        $base = strtoupper($base);

        $cached = Cache::get($this->key($base));

        if ($cached !== null) {
            return $cached;
        }

        $fresh = $this->fetch($base);

        if ($fresh !== null) {
            Cache::put($this->key($base), $fresh, self::TTL);
            Cache::forever($this->staleKey($base), $fresh);

            return $fresh;
        }

        $stale = Cache::get($this->staleKey($base));

        if ($stale !== null) {
            return [...$stale, 'is_stale' => true];
        }

        // Belum pernah sukses sama sekali (mis. deploy pertama saat
        // penyedia down). Frontend harus menyembunyikan kartunya.
        return [
            'base' => $base,
            'rates' => [],
            'updated_at' => null,
            'next_update_at' => null,
            'is_stale' => true,
            'source' => $this->driver(),
            'attribution' => $this->attribution(),
            'eol_at' => null,
        ];
    }

    /** Kembalikan null kalau gagal, supaya pemanggil bisa jatuh ke stale. */
    private function fetch(string $base): ?array
    {
        try {
            $response = Http::timeout(8)
                ->retry(2, 200, throw: false)
                ->get($this->endpoint($base));

            if ($response->failed()) {
                Log::warning('Gagal ambil kurs', ['status' => $response->status()]);

                return null;
            }

            $body = $response->json();

            if (($body['result'] ?? null) !== 'success' || ! isset($body['rates'])) {
                Log::warning('Respons kurs tidak sesuai harapan', ['body' => $body]);

                return null;
            }

            return $this->transform($base, $body);
        } catch (\Throwable $e) {
            Log::warning('Error saat ambil kurs: ' . $e->getMessage());

            return null;
        }
    }

    private function transform(string $base, array $body): array
    {
        $rates = [];

        foreach (self::CURRENCIES as $code => $meta) {
            $value = $body['rates'][$code] ?? null;

            if (! is_numeric($value) || (float) $value <= 0) {
                continue;
            }

            /*
             * Penyedia mengirim "1 IDR = 0.0000605 USD". Yang ingin
             * ditampilkan adalah kebalikannya ("1 USD = Rp16.529"),
             * jadi dibalik di sini — bukan di frontend, supaya web dan
             * mobile tidak menghitung pembulatan sendiri-sendiri.
             */
            $perUnit = 1 / (float) $value;

            $rates[] = [
                'code' => $code,
                'name' => $meta['name'],
                'flag' => $meta['flag'],
                'value' => round($perUnit, 2),
                'value_formatted' => 'Rp' . number_format($perUnit, 0, ',', '.'),
                'label' => "1 {$code}",
            ];
        }

        return [
            'base' => $base,
            'rates' => $rates,
            'updated_at' => $body['time_last_update_utc'] ?? null,
            'next_update_at' => $body['time_next_update_utc'] ?? null,
            'is_stale' => false,
            'source' => $this->driver(),
            'attribution' => $this->attribution(),

            /*
             * Penyedia mengisi field ini kalau endpoint gratisnya
             * dijadwalkan dimatikan. Diteruskan apa adanya supaya bisa
             * dipantau tanpa harus membaca dokumentasi mereka lagi.
             */
            'eol_at' => $body['time_eol'] ?? null,
        ];
    }

    /**
     * Tanpa API key memakai endpoint terbuka; begitu
     * EXCHANGERATE_API_KEY diisi, otomatis pindah ke v6 tanpa
     * mengubah kode pemanggil.
     */
    private function endpoint(string $base): string
    {
        $key = config('services.fx.key');

        return $key
            ? "https://v6.exchangerate-api.com/v6/{$key}/latest/{$base}"
            : "https://open.er-api.com/v6/latest/{$base}";
    }

    private function driver(): string
    {
        return config('services.fx.key') ? 'exchangerate-api-v6' : 'open.er-api.com';
    }

    /** Endpoint terbuka mewajibkan atribusi; tampilkan di kartu kurs. */
    private function attribution(): string
    {
        return 'Rates By Exchange Rate API';
    }

    private function key(string $base): string
    {
        return "fx.rates.{$base}";
    }

    private function staleKey(string $base): string
    {
        return "fx.rates.stale.{$base}";
    }
}