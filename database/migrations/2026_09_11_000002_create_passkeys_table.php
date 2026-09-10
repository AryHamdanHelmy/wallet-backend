<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passkey = kredensial WebAuthn (Face ID / Touch ID / sidik jari / Windows Hello).
 * Satu user boleh punya banyak (HP + laptop). Private key TIDAK pernah
 * meninggalkan perangkat user; server hanya menyimpan public key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Label untuk user, mis. "iPhone" / "Chrome di Windows"
            $table->string('name', 60);

            // ID kredensial dari authenticator, disimpan sebagai base64url.
            // 512 karakter cukup (ID umumnya 16–64 byte) dan tetap muat
            // di batas index unik InnoDB (512 x 4 byte utf8mb4 = 2048 < 3072).
            $table->string('credential_id', 512)->unique();

            // Public key format PEM, dipakai verifikasi tanda tangan saat login
            $table->text('public_key');

            // Counter anti-kloning. Passkey yang tersinkron (iCloud/Google)
            // biasanya selalu 0, jadi hanya dicek kalau > 0.
            $table->unsignedBigInteger('sign_count')->default(0);

            $table->uuid('aaguid')->nullable();          // model authenticator
            $table->boolean('backup_eligible')->default(false);
            $table->boolean('backed_up')->default(false); // tersinkron ke cloud?

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
