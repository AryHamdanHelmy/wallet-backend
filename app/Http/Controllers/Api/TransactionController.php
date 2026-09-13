<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'direction' => ['nullable', 'in:in,out'],
            'type' => ['nullable', 'in:topup,transfer'],
        ]);
        $transactions = $request->user()
            ->wallet
            ->transactions()
            ->with('counterparty.user:id,username,name')
            ->when($validated['direction'] ?? null, fn ($q, $d) => $q->where('direction', $d))
            ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => TransactionResource::collection($transactions)->response()->getData(true),
        ]);
    }
}