<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IssuesAuthTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePinRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Halaman Akun: ubah data diri dan ganti PIN.
 *
 * Yang TIDAK ada di sini, dan itu disengaja:
 *
 * - Ubah Koku ID. Username dipakai sebagai tujuan transfer dan tersimpan
 *   di kepala orang lain ("kirim ke @hamdan"). Kalau bisa diganti bebas,
 *   username bekas bisa diambil orang lain dan uang nyasar ke akun yang
 *   salah. Kalau nanti tetap dibutuhkan, wajib disertai masa tunggu
 *   sebelum username lama boleh dipakai ulang.
 *
 * - Ubah nomor HP. Nomor itu jalur OTP sekaligus identitas login, jadi
 *   penggantiannya harus lewat alur verifikasi OTP sendiri (nomor lama
 *   dan nomor baru), bukan form profil biasa.
 */
class AccountController extends Controller
{
    use IssuesAuthTokens;

    /** PATCH /api/account/profile */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name', 'email']))->save();

        return response()->json([
            'success' => true,
            'message' => 'Profil diperbarui.',
            'data' => $this->presentUser($user->fresh()),
        ]);
    }

    /** POST /api/account/pin */
    public function updatePin(UpdatePinRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->current_pin, $user->pin)) {
            return response()->json([
                'success' => false,
                'message' => 'PIN saat ini salah.',
                'errors' => ['current_pin' => ['PIN saat ini salah.']],
            ], 422);
        }

        /*
         * Selain mengganti PIN, counter gagal dan status kunci ikut
         * dibersihkan. Tanpa ini, user yang lupa PIN lalu menggantinya
         * masih membawa sisa lockout dan tetap ditolak saat login —
         * bug yang sangat membingungkan buat yang mengalaminya.
         *
         * forceFill dipakai karena pin dan kolom lockout sengaja tidak
         * masuk $fillable di model User.
         */
        $user->forceFill([
            'pin' => $request->pin,
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();

        /*
         * Ganti PIN adalah cara user mengusir orang lain dari akunnya.
         * Kalau token perangkat lain dibiarkan hidup, PIN baru tidak ada
         * gunanya — penyusup tetap punya akses. Token perangkat ini
         * dipertahankan supaya user tidak ikut terlempar keluar.
         */
        $currentTokenId = $request->user()->currentAccessToken()->id;
        $user->tokens()->whereKeyNot($currentTokenId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'PIN berhasil diganti. Perangkat lain sudah dikeluarkan.',
        ]);
    }

    /**
     * DELETE /api/account/sessions — keluar dari semua perangkat lain
     * tanpa mengganti PIN.
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()->id;

        $revoked = $request->user()->tokens()->whereKeyNot($currentTokenId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Perangkat lain sudah dikeluarkan.',
            'data' => ['revoked' => $revoked],
        ]);
    }
}