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
        $transactions = $request->user()
            ->wallet
            ->transactions()
            ->with('counterparty.user:id,username,name')
            ->latest('id')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => TransactionResource::collection($transactions)->response()->getData(true),
        ]);
    }
}