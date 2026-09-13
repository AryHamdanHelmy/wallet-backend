<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FxRateService;
use Illuminate\Http\JsonResponse;

class RateController extends Controller
{
    public function __construct(
        private readonly FxRateService $fx,
    ) {}

    /** GET /api/rates */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->fx->latest('IDR'),
        ]);
    }
}