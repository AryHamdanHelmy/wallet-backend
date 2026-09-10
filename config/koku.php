<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OTP (verifikasi nomor HP)
    |--------------------------------------------------------------------------
    |
    | driver:
    |   - "log"    : kode OTP ditulis ke storage/logs/laravel.log (untuk dev)
    |   - "fonnte" : dikirim via WhatsApp lewat api.fonnte.com
    |
    | expose_in_response: kalau true DAN environment local/testing, kode OTP
    | ikut dikirim di response API (field "debug_otp") supaya gampang dites.
    | Di production flag ini diabaikan walaupun di-set true.
    |
    */

    'otp' => [
        'driver' => env('OTP_DRIVER', 'log'),
        'length' => 6,
        'ttl' => (int) env('OTP_TTL', 300),                     // detik, masa berlaku kode
        'max_attempts' => 5,                                     // salah input sebelum kode hangus
        'resend_cooldown' => (int) env('OTP_RESEND_COOLDOWN', 60), // detik antar kirim ulang
        'max_sends' => 5,                                        // total kirim per sesi registrasi
        'expose_in_response' => (bool) env('OTP_EXPOSE', false),

        'fonnte' => [
            'url' => env('FONNTE_URL', 'https://api.fonnte.com/send'),
            'token' => env('FONNTE_TOKEN'),
        ],

        'message' => 'Kode verifikasi Koku kamu: :code. Berlaku :minutes menit. '
            . 'Jangan berikan kode ini ke siapa pun, termasuk pihak Koku.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Registrasi
    |--------------------------------------------------------------------------
    |
    | Data langkah 1 (nama, HP, Koku ID, PIN ter-hash) disimpan sementara di
    | cache sampai OTP terverifikasi. User baru dibuat setelah itu.
    |
    */

    'registration' => [
        'ttl' => (int) env('REGISTRATION_TTL', 600), // detik
    ],

    /*
    |--------------------------------------------------------------------------
    | PIN
    |--------------------------------------------------------------------------
    |
    | Setelah max_attempts kali salah, akun dikunci selama lock_minutes.
    | Mock: "PIN salah (tersisa 2 kali)" -> max_attempts = 3.
    |
    */

    'pin' => [
        'length' => 6,
        'max_attempts' => 3,
        'lock_minutes' => (int) env('PIN_LOCK_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | WebAuthn / Passkey (Face ID, Touch ID, sidik jari)
    |--------------------------------------------------------------------------
    |
    | rp_id    : domain FRONTEND tanpa skema/port, mis. "koku.vercel.app".
    |            Untuk dev pakai "localhost".
    | origins  : origin frontend yang boleh memanggil, dipisah koma.
    |            mis. "https://koku.vercel.app,http://localhost:5174"
    |
    | WebAuthn hanya jalan di HTTPS, kecuali localhost.
    |
    */

    'webauthn' => [
        'rp_name' => env('WEBAUTHN_RP_NAME', 'Koku'),
        'rp_id' => env('WEBAUTHN_RP_ID', 'localhost'),
        'origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WEBAUTHN_ORIGINS', 'http://localhost:5174'))
        ))),
        'timeout' => 60,             // detik, batas waktu prompt biometrik
        'challenge_ttl' => 120,      // detik, masa berlaku challenge di cache
    ],

];