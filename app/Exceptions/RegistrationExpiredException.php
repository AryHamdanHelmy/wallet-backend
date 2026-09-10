<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesi registrasi (data langkah 1 di cache) sudah habis atau tidak ada.
 * Frontend harus mengarahkan user kembali ke langkah 1.
 */
class RegistrationExpiredException extends Exception
{
    protected $message = 'Sesi pendaftaran sudah berakhir. Isi ulang data kamu.';

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'code' => 'registration_expired',
            'message' => $this->getMessage(),
        ], 410);
    }
}