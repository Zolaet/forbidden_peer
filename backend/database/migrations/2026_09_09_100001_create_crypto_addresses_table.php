<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stable, unique per-user deposit addresses derived from the platform's
     * master seed (one row per user per network per currency).
     */
    public function up(): void
    {
        Schema::create('crypto_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('network', 10);          // 'testnet' | 'mainnet'
            $table->string('currency', 10)->default('USDT');
            $table->string('address', 64);          // EIP-55 style hex address
            $table->unsignedBigInteger('derivation_index'); // HD path m/44'/60'/{network}'/0/{index}
            $table->timestamps();

            $table->unique(['user_id', 'network', 'currency']);
            $table->unique(['network', 'address']);
            $table->unique(['network', 'derivation_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_addresses');
    }
};
