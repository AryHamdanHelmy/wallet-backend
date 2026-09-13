<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel notifikasi bawaan Laravel (channel "database").
 *
 * Dipakai buat bell di pojok kanan atas dashboard: transfer masuk,
 * transfer keluar, top-up berhasil, dan nanti info keamanan
 * (passkey baru didaftarkan, login dari perangkat lain).
 *
 * Struktur sengaja mengikuti bawaan Laravel (id uuid + morph notifiable
 * + kolom data JSON), supaya bisa pakai $user->notify() dan relasi
 * $user->notifications / unreadNotifications tanpa kode tambahan.
 *
 * Tambahan dari kami: index [notifiable, read_at] supaya query
 * "hitung yang belum dibaca" tetap murah saat riwayat menumpuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at'],
                'notifications_unread_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
