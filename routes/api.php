<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasskeyController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth (tamu)
|--------------------------------------------------------------------------
|
| Throttle di sini per IP. Pastikan trustProxies aktif di bootstrap/app.php,
| kalau tidak, di Railway semua user terbaca dengan IP proxy yang sama dan
| berbagi satu kuota.
|
*/

Route::prefix('auth')->group(function () {

    // Registrasi 2 langkah
    Route::post('/register', [AuthController::class, 'registerStart'])
        ->middleware('throttle:5,1');

    Route::post('/register/resend', [AuthController::class, 'registerResend'])
        ->middleware('throttle:5,1');

    Route::post('/register/verify', [AuthController::class, 'registerVerify'])
        ->middleware('throttle:10,1');

    Route::get('/register/{registrationId}', [AuthController::class, 'registerSummary'])
        ->whereUuid('registrationId')
        ->middleware('throttle:30,1');

    // Dipanggil sambil mengetik (frontend wajib debounce)
    Route::get('/username-availability', [AuthController::class, 'usernameAvailability'])
        ->middleware('throttle:60,1');

    // Login PIN
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');

    // Login Face ID / sidik jari (passkey)
    Route::post('/passkey/options', [PasskeyController::class, 'loginOptions'])
        ->middleware('throttle:20,1');

    Route::post('/passkey/verify', [PasskeyController::class, 'loginVerify'])
        ->middleware('throttle:10,1');
});

/*
|--------------------------------------------------------------------------
| Butuh login
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Kelola passkey (daftarkan Face ID di perangkat ini, lihat, hapus)
    Route::prefix('passkeys')->group(function () {
        Route::get('/', [PasskeyController::class, 'index']);
        Route::post('/options', [PasskeyController::class, 'registerOptions'])
            ->middleware('throttle:10,1');
        Route::post('/', [PasskeyController::class, 'registerVerify'])
            ->middleware('throttle:10,1');
        Route::delete('/{passkey}', [PasskeyController::class, 'destroy']);
    });

    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/transactions', [TransactionController::class, 'index']);

    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/topup', [WalletController::class, 'topup']);
        Route::post('/transfer', [WalletController::class, 'transfer']);
    });
});