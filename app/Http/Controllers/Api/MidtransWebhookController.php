<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentGateway;
use App\Services\TopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MidtransWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly TopupService $topups,
    ) {}

    /**
     * Endpoint ini publik, jadi keamanannya bergantung sepenuhnya pada
     * signature. Jangan pernah percaya isi payload sebelum diverifikasi.
     *
     * Selalu balas 200 untuk payload yang sah, termasuk saat notifikasinya
     * diabaikan. Kalau tidak, Midtrans akan mengirim ulang terus-menerus
     * untuk order yang memang tidak akan pernah bisa diproses.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $notification = $this->gateway->parseNotification($request->all());

        if ($notification === null) {
            Log::warning('Webhook Midtrans dengan signature tidak valid', [
                'order_id' => $request->input('order_id'),
                'ip'       => $request->ip(),
            ]);

            return response()->json(['success' => false], 403);
        }

        $this->topups->handleNotification($notification);

        return response()->json(['success' => true]);
    }
}