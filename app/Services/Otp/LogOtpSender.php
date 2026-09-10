<?php

namespace App\Services\Otp;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * Driver dev: tidak mengirim apa pun, cuma menulis ke storage/logs/laravel.log.
 * Pantau dengan:  Get-Content storage\logs\laravel.log -Wait -Tail 20
 */
class LogOtpSender implements OtpSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('[OTP] ' . PhoneNumber::format($phone) . ' :: ' . $message);
    }
}