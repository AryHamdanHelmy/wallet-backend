<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use App\Services\Otp\FonnteOtpSender;
use App\Services\Otp\LogOtpSender;
use App\Services\Otp\OtpSender;
use App\Services\Payment\MidtransGateway;
use App\Services\Payment\PaymentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Pilih driver pengirim OTP dari config('koku.otp.driver').
        $this->app->bind(OtpSender::class, function () {
            return match (config('koku.otp.driver')) {
                'fonnte' => new FonnteOtpSender(
                    config('koku.otp.fonnte.url'),
                    config('koku.otp.fonnte.token'),
                ),
                default => new LogOtpSender(),
            };
        });
        $this->app->singleton(PaymentGateway::class, fn () => new MidtransGateway(
            serverKey: (string) config('midtrans.server_key'),
            baseUrl: (string) config('midtrans.base_url'),
            timeout: (int) config('midtrans.timeout'),
        ));
    }

    public function boot(): void
    {
        User::observe(UserObserver::class);
    }
}