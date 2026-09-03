<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void 
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 10)->default('USDT');
            
            // 18 digits precision, 8 decimal places for crypto precision
            $table->decimal('available_balance', 18, 8)->default(0.00000000);
            $table->decimal('escrow_balance', 18, 8)->default(0.00000000);
            
            $table->timestamps();

            // Prevent a user from having duplicate wallet entries for the same currency
            $table->unique(['user_id', 'currency']);
        });
    }

    public function down(): void 
    {
        Schema::dropIfExists('wallets');
    }
};