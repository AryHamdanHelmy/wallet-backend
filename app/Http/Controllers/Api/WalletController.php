<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\TopupRequest;
use App\Http\Requests\Wallet\TransferRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WalletResource;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        return response()->json([
            'success' => true,
            'data' => new WalletResource($request->user()->wallet),
        ]);
    }

    public function topup(TopupRequest $request): JsonResponse
    {
        $transaction = $this->walletService->topup(
            $request->user()->wallet,
            (int) $request->amount,
            $request->idempotency_key,
        );

        return response()->json([
            'success' => true,
            'message' => 'Top-up berhasil.',
            'data' => new TransactionResource($transaction),
        ], 201);
    }

    public function transfer(TransferRequest $request): JsonResponse
    {
        $transaction = $this->walletService->transfer(
            $request->user()->wallet,
            $request->recipientUser(),
            (int) $request->amount,
            $request->idempotency_key,
        );

        return response()->json([
            'success' => true,
            'message' => 'Transfer berhasil.',
            'data' => new TransactionResource($transaction),
        ], 201);
    }
}