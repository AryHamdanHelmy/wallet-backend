<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Ringkasan arus kas untuk halaman Keuangan.
 *
 * Pengelompokan per bulan dilakukan di PHP, bukan lewat GROUP BY dengan
 * fungsi tanggal. Alasannya portabilitas: SQLite memakai strftime()
 * sementara MySQL memakai DATE_FORMAT(), jadi satu query mentah pasti
 * pecah di salah satu lingkungan — dan di sini development memakai
 * SQLite sedangkan produksi bisa MySQL.
 *
 * Yang diambil hanya tiga kolom untuk rentang terbatas (maksimal 12
 * bulan), jadi beban memorinya kecil. Kalau suatu saat satu akun bisa
 * punya puluhan ribu transaksi per bulan, barulah pindah ke agregasi di
 * database dengan percabangan per driver.
 *
 * CATATAN: belum ada rincian per kategori (makan, transport, dsb).
 * Tabel transactions tidak menyimpan kategori, dan menebaknya dari tipe
 * transaksi akan menghasilkan angka yang terlihat meyakinkan tapi salah.
 */
class WalletSummaryController extends Controller
{
    /** GET /api/wallet/summary?months=6 */
    public function __invoke(Request $request): JsonResponse
    {
        $months = (int) $request->integer('months', 6);
        $months = max(1, min($months, 12));

        $since = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $rows = $request->user()
            ->wallet
            ->transactions()
            ->select(['direction', 'amount', 'created_at'])
            ->where('created_at', '>=', $since)
            ->get();

        // Kerangka bulan dibuat lebih dulu supaya bulan tanpa transaksi
        // tetap muncul sebagai nol, bukan hilang dari grafik.
        $buckets = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $since->copy()->addMonths($i);

            $buckets[$month->format('Y-m')] = [
                'month' => $month->format('Y-m'),
                'label' => $month->locale('id')->isoFormat('MMM'),
                'income' => 0,
                'expense' => 0,
            ];
        }

        foreach ($rows as $row) {
            $key = $row->created_at->format('Y-m');

            if (! isset($buckets[$key])) {
                continue;
            }

            $field = $row->direction === 'in' ? 'income' : 'expense';
            $buckets[$key][$field] += (int) $row->amount;
        }

        $series = array_values($buckets);

        foreach ($series as $i => $bucket) {
            $series[$i]['net'] = $bucket['income'] - $bucket['expense'];
        }

        $current = end($series) ?: ['income' => 0, 'expense' => 0, 'net' => 0];

        return response()->json([
            'success' => true,
            'data' => [
                'current_month' => [
                    'income' => $current['income'],
                    'expense' => $current['expense'],
                    'net' => $current['net'],
                    'label' => Carbon::now()->locale('id')->isoFormat('MMMM YYYY'),
                ],
                'series' => $series,
                'transaction_count' => $rows->count(),
            ],
        ]);
    }
}