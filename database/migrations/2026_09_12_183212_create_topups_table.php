<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            $table->string('order_id')->unique();          // dikirim ke Midtrans
            $table->unsignedBigInteger('amount');          // yang masuk ke saldo
            $table->unsignedBigInteger('fee')->default(0); // biaya admin
            $table->unsignedBigInteger('gross_amount');    // amount + fee, yang dibayar user

            $table->string('method');                      // va | qris | gopay | shopeepay
            $table->string('channel')->nullable();         // bca | bni | bri | mandiri | permata
            $table->string('status')->default('pending');  // pending | paid | expired | failed

            $table->string('gateway_transaction_id')->nullable();
            $table->json('payment_payload')->nullable();   // no. VA / QR string / deeplink

            $table->foreignId('transaction_id')->nullable()
                ->constrained('transactions')->nullOnDelete(); // mutasi ledger setelah lunas

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topups');
    }
};