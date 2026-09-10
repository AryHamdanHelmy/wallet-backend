<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IssuesAuthTokens;
use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Services\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasskeyController extends Controller
{
    use IssuesAuthTokens;

    public function __construct(
        private readonly PasskeyService $passkeys,
    ) {}

    // ------------------------------------------------------------------
    // Tamu: masuk dengan Face ID
    // ------------------------------------------------------------------

    /** POST /auth/passkey/options */
    public function loginOptions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->passkeys->loginOptions(),
        ]);
    }

    /** POST /auth/passkey/verify */
    public function loginVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'remember' => ['sometimes', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:60'],
            ...$this->credentialRules(['clientDataJSON', 'authenticatorData', 'signature']),
            'credential.response.userHandle' => ['nullable', 'string', 'max:512'],
        ]);

        $user = $this->passkeys->authenticate($validated['challenge_id'], $validated['credential']);

        return response()->json([
            'success' => true,
            'message' => 'Selamat datang kembali.',
            'data' => [
                'user' => $this->presentUser($user),
                ...$this->issueToken($user, $request, remember: $request->boolean('remember', true)),
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Login: kelola passkey milik sendiri
    // ------------------------------------------------------------------

    /** GET /passkeys */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()->passkeys()
                ->latest()
                ->get(['id', 'name', 'backed_up', 'last_used_at', 'created_at']),
        ]);
    }

    /** POST /passkeys/options */
    public function registerOptions(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->passkeys->registrationOptions($request->user()),
        ]);
    }

    /** POST /passkeys */
    public function registerVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            ...$this->credentialRules(['clientDataJSON', 'attestationObject']),
        ]);

        $passkey = $this->passkeys->register(
            $request->user(),
            $validated['credential'],
            $validated['name'] ?? $this->guessDeviceName($request->userAgent()),
        );

        return response()->json([
            'success' => true,
            'message' => 'Face ID aktif. Lain kali kamu bisa masuk tanpa PIN.',
            'data' => $passkey->only(['id', 'name', 'backed_up', 'created_at']),
        ], 201);
    }

    /** DELETE /passkeys/{passkey} */
    public function destroy(Request $request, Passkey $passkey): JsonResponse
    {
        // 404, bukan 403: tidak membocorkan bahwa ID passkey itu ada.
        abort_unless($passkey->user_id === $request->user()->id, 404);

        $passkey->delete();

        return response()->json([
            'success' => true,
            'message' => 'Face ID di perangkat itu dinonaktifkan.',
        ]);
    }

    // ------------------------------------------------------------------

    private function credentialRules(array $responseFields): array
    {
        $rules = [
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string', 'max:512'],
            'credential.rawId' => ['required', 'string', 'max:512'],
            'credential.type' => ['required', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ];

        foreach ($responseFields as $field) {
            $rules["credential.response.{$field}"] = ['required', 'string', 'max:16384'];
        }

        return $rules;
    }

    private function guessDeviceName(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        return match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            default => 'Perangkat ini',
        };
    }
}