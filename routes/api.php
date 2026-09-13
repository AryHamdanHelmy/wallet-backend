<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\MidtransWebhookController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasskeyController;
use App\Http\Controllers\Api\RateController;
use App\Http\Controllers\Api\TopupController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserSearchController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WalletSummaryController;
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
| Webhook (publik, diautentikasi lewat signature)
|--------------------------------------------------------------------------
|
| Bukan di dalam grup auth: yang memanggil server Midtrans, bukan user.
| Keamanannya sepenuhnya dari signature_key, bukan token.
|
| URL yang didaftarkan di dashboard Midtrans:
| https://<domain-railway>/api/midtrans/notification
|
| Throttle sengaja longgar supaya retry beruntun dari gateway tidak kena 429.
|
*/
Route::get('/rates', [RateController::class, 'index'])->middleware('throttle:60,1');

Route::post('/midtrans/notification', MidtransWebhookController::class)
    ->middleware('throttle:120,1');

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
    Route::get('/wallet/summary', WalletSummaryController::class);
    Route::get('/contacts/frequent', [ContactController::class, 'frequent']);
    Route::get('/users/search', UserSearchController::class)->middleware('throttle:30,1');

    /*
    | Top-up
    |
    | Urutan penting: /topups/methods harus di atas /topups/{orderId},
    | kalau tidak kata "methods" akan tertangkap sebagai order_id.
    */
    Route::get('/topups/methods', [TopupController::class, 'methods']);
    Route::get('/topups', [TopupController::class, 'index']);
    Route::get('/topups/{orderId}', [TopupController::class, 'show'])
        ->middleware('throttle:120,1'); // polling saat user menunggu bayar

    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/topups', [TopupController::class, 'store']);
        Route::post('/transfer', [WalletController::class, 'transfer']);
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id');
        Route::delete('/{id}', [NotificationController::class, 'destroy'])->whereUuid('id');
    });

    Route::prefix('account')->group(function () {
    Route::patch('/profile', [AccountController::class, 'updateProfile']);
    Route::post('/pin', [AccountController::class, 'updatePin'])->middleware('throttle:5,1');
    Route::delete('/sessions', [AccountController::class, 'revokeOtherSessions']);
    });
});

/*
|--------------------------------------------------------------------------
| Shortcut dev: tambah saldo tanpa bayar
|--------------------------------------------------------------------------
|
| HANYA hidup di local/testing. Di production route ini tidak terdaftar
| sama sekali, jadi tidak ada yang bisa mencetak saldo sendiri.
|
| Dipakai oleh WalletApiTest dan WalletTransferTest yang sudah ada.
|
*/

if (app()->environment(['local', 'testing'])) {
    Route::middleware('auth:sanctum')->post('/topup', [WalletController::class, 'topup']);
}