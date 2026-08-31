<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('counterparty_wallet_id')
                ->nullable()
                ->constrained('wallets')
                ->nullOnDelete();

            $table->uuid('reference_id');
            $table->string('idempotency_key')->nullable()->unique();

            $table->enum('type', ['topup', 'transfer']);
            $table->enum('direction', ['in', 'out']);

            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');

            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index('reference_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
