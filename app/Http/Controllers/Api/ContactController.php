<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Support\Initials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Kirim Cepat" di dashboard.
 *
 * Sengaja TIDAK memakai tabel contacts. Daftar ini diturunkan dari
 * riwayat transfer keluar, jadi tidak ada data yang perlu dijaga
 * konsistensinya dan user tidak perlu menambahkan teman secara manual —
 * begitu dia transfer sekali, orangnya muncul di sini.
 *
 * Konsekuensinya: user belum bisa mem-pin atau menyembunyikan kontak.
 * Kalau nanti fitur itu diminta, tambahkan tabel contacts (user_id,
 * contact_user_id, pinned_at, hidden_at) dan gabungkan hasilnya di sini,
 * bukan menggantinya — riwayat tetap sumber yang paling akurat.
 *
 * Inisial avatar dihitung lewat App\Support\Initials, sama seperti di
 * pencarian penerima, supaya satu orang tidak tampil "AP" di satu layar
 * dan "AN" di layar lain.
 */
class ContactController extends Controller
{
    /** GET /api/contacts/frequent?limit=4 */
    public function frequent(Request $request): JsonResponse
    {
        $limit = (int) $request->integer('limit', 4);
        $limit = max(1, min($limit, 10));

        $wallet = $request->user()->wallet;

        /*
         * Diurutkan berdasarkan jumlah transfer dulu, baru waktu terakhir.
         * Artinya orang yang sering ditransfer bertahan di depan meski
         * belakangan jarang. Kalau ternyata terasa "basi", ganti urutannya
         * jadi last_sent_at duluan — jangan campur keduanya jadi skor
         * buatan, susah dijelaskan kalau ada yang protes urutannya aneh.
         */
        $rows = $wallet->transactions()
            ->select([
                'counterparty_wallet_id',
                DB::raw('COUNT(*) as transfer_count'),
                DB::raw('MAX(created_at) as last_sent_at'),
            ])
            ->where('type', 'transfer')
            ->where('direction', 'out')
            ->whereNotNull('counterparty_wallet_id')
            ->groupBy('counterparty_wallet_id')
            ->orderByDesc('transfer_count')
            ->orderByDesc('last_sent_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // Satu query untuk semua wallet, bukan per baris (hindari N+1).
        $wallets = Wallet::with('user:id,name,username')
            ->whereIn('id', $rows->pluck('counterparty_wallet_id'))
            ->get()
            ->keyBy('id');

        $contacts = $rows
            ->map(function ($row) use ($wallets) {
                $user = $wallets->get($row->counterparty_wallet_id)?->user;

                // Wallet bisa saja sudah terhapus (transaksi pakai
                // nullOnDelete, tapi baris lama masih menyimpan id-nya).
                if ($user === null) {
                    return null;
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'initials' => Initials::from($user->name),
                    'transfer_count' => (int) $row->transfer_count,
                    'last_sent_at' => $row->last_sent_at,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'data' => $contacts,
        ]);
    }

}