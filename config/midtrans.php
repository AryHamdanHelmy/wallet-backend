<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kredensial
    |--------------------------------------------------------------------------
    |
    | Server key dipakai untuk auth ke Core API (Basic auth, base64 dari
    | "server_key:") sekaligus untuk verifikasi signature webhook.
    | JANGAN pernah kirim server key ke frontend.
    |
    */

    'server_key' => env('MIDTRANS_SERVER_KEY'),
    'client_key' => env('MIDTRANS_CLIENT_KEY'),
    'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),

    'base_url' => env('MIDTRANS_IS_PRODUCTION', false)
        ? 'https://api.midtrans.com'
        : 'https://api.sandbox.midtrans.com',

    'timeout' => 15, // detik

    /*
    |--------------------------------------------------------------------------
    | Batas nominal top-up
    |--------------------------------------------------------------------------
    |
    | Terpisah dari wallet.min_transaction karena minimum top-up lewat gateway
    | lebih tinggi: kalau user isi Rp1.000 sementara fee VA Rp4.000, dia bayar
    | Rp5.000 untuk saldo Rp1.000. Tidak masuk akal.
    |
    */

    'min_amount' => 10_000,
    'max_amount' => 10_000_000,

    /*
    |--------------------------------------------------------------------------
    | Masa berlaku pembayaran
    |--------------------------------------------------------------------------
    |
    | Dikirim ke Midtrans lewat custom_expiry sekaligus disimpan di kolom
    | expires_at untuk countdown di frontend.
    |
    */

    'expiry_minutes' => (int) env('MIDTRANS_EXPIRY_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Metode pembayaran
    |--------------------------------------------------------------------------
    |
    | fee_flat    : rupiah, ditambahkan apa adanya
    | fee_percent : persen dari nominal top-up
    | Keduanya bisa dipakai bareng; total dibulatkan ke atas ke rupiah penuh.
    |
    | enabled=false untuk menyembunyikan metode dari daftar tanpa hapus kode.
    | Angka di bawah perkiraan tarif Midtrans, sesuaikan dengan MDR kamu.
    |
    */

    'methods' => [

        'va' => [
            'label' => 'Virtual Account',
            'enabled' => true,
            'fee_flat' => 4_000,
            'fee_percent' => 0,
            'channels' => [
                'bca' => ['label' => 'BCA Virtual Account', 'enabled' => true],
                'bni' => ['label' => 'BNI Virtual Account', 'enabled' => true],
                'bri' => ['label' => 'BRI Virtual Account', 'enabled' => true],
                'permata' => ['label' => 'Permata Virtual Account', 'enabled' => true],
                'mandiri' => ['label' => 'Mandiri Bill Payment', 'enabled' => true],
            ],
        ],

        'qris' => [
            'label' => 'QRIS',
            'enabled' => true,
            'fee_flat' => 0,
            'fee_percent' => 0.7,
            'channels' => [],
        ],

        'gopay' => [
            'label' => 'GoPay',
            'enabled' => true,
            'fee_flat' => 0,
            'fee_percent' => 2,
            'channels' => [],
        ],

        'shopeepay' => [
            'label' => 'ShopeePay',
            'enabled' => true,
            'fee_flat' => 0,
            'fee_percent' => 2,
            'channels' => [],
        ],
    ],
];