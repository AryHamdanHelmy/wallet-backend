<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IssuesAuthTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterStartRequest;
use App\Http\Requests\Auth\RegisterVerifyRequest;
use App\Models\User;
use App\Services\PinAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use IssuesAuthTokens;

    public function __construct(
        private readonly PinAuthService $pinAuth,
    ) {}

    // ------------------------------------------------------------------
    // Registrasi
    // ------------------------------------------------------------------

    /** POST /auth/register — Langkah 1: simpan data sementara + kirim OTP. */
    public function registerStart(RegisterStartRequest $request): JsonResponse
    {
        $data = $registration->start($request->safe()->only(['name', 'phone', 'username', 'pin']));

        return response()->json([
            'success' => true,
            'message' => 'Kode verifikasi dikirim ke ' . $data['phone_masked'] . '.',
            'data' => $data,
        ], 202);
    }

    /** POST /auth/register/resend — kirim ulang OTP. */
    public function registerResend(Request $request): JsonResponse
    {
        $validated = $request->validate(
            ['registration_id' => ['required', 'uuid']],
            ['registration_id.*' => 'Sesi pendaftaran tidak valid. Isi ulang data kamu.'],
        );

        $data = $registration->resend($validated['registration_id']);

        return response()->json([
            'success' => true,
            'message' => 'Kode baru dikirim ke ' . $data['phone_masked'] . '.',
            'data' => $data,
        ]);
    }

    /** GET /auth/register/{registrationId} — info sesi untuk halaman OTP. */
    public function registerSummary(string $registrationId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $registration->summary($registrationId),
        ]);
    }

    /** POST /auth/register/verify — Langkah 2: OTP valid -> akun dibuat + token. */
    public function registerVerify(RegisterVerifyRequest $request): JsonResponse
    {
        $user = $registration->complete(
            $request->validated('registration_id'),
            $request->validated('otp'),
        );

        // Baru daftar = perangkat milik sendiri, pakai token berumur panjang.
        $token = $this->issueToken($user, $request, remember: true);

        return response()->json([
            'success' => true,
            'message' => 'Akun Koku kamu sudah aktif.',
            'data' => [
                'user' => $this->presentUser($user),
                ...$token,
            ],
        ], 201);
    }

    /**
     * GET /auth/username-availability?username=hamdan
     *
     * Selalu 200 (bukan 422) supaya UI "✓ Tersedia" cukup membaca "available".
     * Aturannya sama persis dengan validasi submit (RegisterStartRequest).
     */
    public function usernameAvailability(Request $request): JsonResponse
    {
        $username = mb_strtolower(ltrim(trim((string) $request->query('username', '')), '@'));

        $validator = Validator::make(
            ['username' => $username],
            ['username' => RegisterStartRequest::usernameRules()],
            RegisterStartRequest::usernameMessages(),
        );

        $available = $validator->passes();

        return response()->json([
            'success' => true,
            'data' => [
                'username' => $username,
                'available' => $available,
                'message' => $available ? 'Tersedia' : $validator->errors()->first('username'),
                'suggestions' => $available ? [] : $this->suggestUsernames($username),
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Login / sesi
    // ------------------------------------------------------------------

    /** POST /auth/login — Nomor HP / Koku ID + PIN. */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->pinAuth->attempt(
            $request->validated('identifier'),
            $request->validated('pin'),
        );

        $token = $this->issueToken($user, $request, remember: $request->boolean('remember'));

        return response()->json([
            'success' => true,
            'message' => 'Selamat datang kembali.',
            'data' => [
                'user' => $this->presentUser($user),
                ...$token,
            ],
        ]);
    }

    /** GET /me */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('wallet');

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->presentUser($user),
                'wallet' => $user->wallet,
            ],
        ]);
    }

    /** POST /auth/logout — cabut token perangkat ini saja. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kamu sudah keluar.',
        ]);
    }

    /**
     * Saran Koku ID yang masih kosong, mis. "hamdan" -> ["hamdan_27", "hamdan804", ...].
     */
    private function suggestUsernames(string $base): array
    {
        $base = substr(preg_replace('/[^a-z0-9_]/', '', $base), 0, 15);

        if (! preg_match('/^[a-z]/', $base) || strlen($base) < 3) {
            return [];
        }

        $candidates = collect(range(1, 8))
            ->map(fn () => random_int(0, 1)
                ? $base . '_' . random_int(10, 99)
                : $base . random_int(100, 999))
            ->unique()
            ->values();

        $taken = User::whereIn('username', $candidates)->pluck('username');

        return $candidates->diff($taken)->take(3)->values()->all();
    }
}