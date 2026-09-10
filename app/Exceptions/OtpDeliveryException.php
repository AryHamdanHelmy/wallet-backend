<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dilempar saat OTP gagal dikirim (gateway down, token salah, nomor ditolak).
 * Punya render() sendiri, jadi tidak perlu didaftarkan di bootstrap/app.php.
 */
class OtpDeliveryException extends Exception
{
    protected $message = 'Kode verifikasi gagal dikirim. Coba lagi sebentar lagi.';

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
        ], 503);
    }
}