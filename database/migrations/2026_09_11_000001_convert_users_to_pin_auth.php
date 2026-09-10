<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mengubah auth dari email + password ke Nomor HP / Koku ID + PIN 6 digit.
 *
 * - password  -> pin (tetap di-hash bcrypt lewat cast 'hashed')
 * - email     -> opsional
 * - phone_verified_at      : diisi saat OTP berhasil diverifikasi
 * - pin_failed_attempts    : counter salah PIN (reset saat login sukses)
 * - pin_locked_until       : akun dikunci sementara setelah batas salah PIN
 *
 * Dipecah jadi beberapa Schema::table() supaya aman di SQLite
 * (rename + change di satu blok bisa bentrok saat table rebuild).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('password', 'pin');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->unsignedTinyInteger('pin_failed_attempts')->default(0)->after('pin');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_failed_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_verified_at', 'pin_failed_attempts', 'pin_locked_until']);
        });

        // Catatan: akan gagal kalau sudah ada user tanpa email.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('pin', 'password');
        });
    }
};
