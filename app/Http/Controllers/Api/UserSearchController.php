<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Initials;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencarian penerima transfer (tombol "Baru" di Kirim Cepat).
 *
 * Aturan pencarian sengaja ketat:
 *   - Nomor HP    : harus cocok PERSIS setelah dinormalisasi.
 *   - Koku ID     : cocok dari awal (prefix), minimal 3 karakter.
 *   - Nama        : TIDAK bisa dicari sama sekali.
 *
 * Alasannya keamanan, bukan kemalasan. Kalau nama ikut dicari dengan
 * LIKE %...%, siapa pun yang login bisa menyapu daftar seluruh pengguna
 * cukup dengan mengetik "a", lalu memetakan nama asli ke Koku ID. Di
 * aplikasi dompet itu bahan mentah buat penipuan ("halo saya Budi dari
 * Koku..."). Pengirim uang sudah tahu Koku ID atau nomor tujuannya —
 * dia tidak perlu menelusuri direktori.
 *
 * Nomor HP juga tidak pernah ikut dikembalikan, supaya endpoint ini
 * tidak bisa dipakai membalik Koku ID menjadi nomor WhatsApp.
 */
class UserSearchController extends Controller
{
    private const MIN_LENGTH = 3;
    private const LIMIT = 5;

    /** GET /api/users/search?q=hamdan */
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $q = ltrim($q, '@');

        if (mb_strlen($q) < self::MIN_LENGTH) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Ketik minimal ' . self::MIN_LENGTH . ' karakter.',
            ]);
        }

        $query = User::query()
            ->select('id', 'name', 'username')
            ->whereKeyNot($request->user()->id)
            ->whereNotNull('phone_verified_at');

        if (PhoneNumber::looksLikePhone($q)) {
            $phone = PhoneNumber::normalize($q);

            // Nomor tidak valid: jangan jatuh ke pencarian username,
            // hasilnya cuma bikin bingung ("0812..." cocok apa?).
            if ($phone === null) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $query->where('phone', $phone);
        } else {
            $query
                ->whereLike('username', $this->escapeLike(mb_strtolower($q)) . '%')
                ->orderBy('username')
                ->limit(self::LIMIT);
        }

        $users = $query->get()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'initials' => Initials::from($user->name),
        ]);

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Tanpa ini, user yang mengetik "%" atau "_" akan mencocokkan
     * semua username sekaligus — persis direktori yang ingin dihindari.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}