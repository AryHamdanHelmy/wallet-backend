<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\CreateTopupRequest;
use App\Http\Resources\TopupResource;
use App\Models\Topup;
use App\Services\TopupService;
use App\Support\TopupMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TopupController extends Controller
{
    public function __construct(
        private readonly TopupService $topups,
    ) {}

    /**
     * Daftar metode pembayaran beserta biaya adminnya.
     * Kalau ?amount= dikirim, fee dan total ikut dihitung.
     */
    public function methods(Request $request): JsonResponse
    {
        $amount = $request->filled('amount') && ctype_digit((string) $request->query('amount'))
            ? (int) $request->query('amount')
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'min_amount' => (int) config('midtrans.min_amount'),
                'max_amount' => (int) config('midtrans.max_amount'),
                'methods'    => TopupMethods::available($amount),
            ],
        ]);
    }

    /** Buat tagihan baru. Saldo belum bertambah di sini. */
    public function store(CreateTopupRequest $request): JsonResponse
    {
        $topup = $this->topups->create(
            $request->user(),
            (int) $request->amount,
            $request->string('method'),
            $request->input('channel'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Silakan selesaikan pembayaran.',
            'data'    => new TopupResource($topup),
        ], 201);
    }

    /** Riwayat top-up, terbaru dulu. */
    public function index(Request $request): JsonResponse
    {
        $topups = Topup::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => TopupResource::collection($topups)->response()->getData(true),
        ]);
    }

    /**
     * Dipakai frontend untuk polling selama user membayar.
     * Query di-scope ke user, jadi order_id milik orang lain tetap 404.
     */
    public function show(Request $request, string $orderId): JsonResponse
    {
        $topup = Topup::where('user_id', $request->user()->id)
            ->where('order_id', $orderId)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => new TopupResource($this->topups->refreshStatus($topup)),
        ]);
    }
}