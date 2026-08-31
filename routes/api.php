<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:20,1');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:20,1');
});

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/transactions', [TransactionController::class, 'index']);

    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/topup', [WalletController::class, 'topup']);
        Route::post('/transfer', [WalletController::class, 'transfer']);
    });
});