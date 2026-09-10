<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use App\Services\Otp\FonnteOtpSender;
use App\Services\Otp\LogOtpSender;
use App\Services\Otp\OtpSender;
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
    }

    public function boot(): void
    {
        User::observe(UserObserver::class);
    }
}