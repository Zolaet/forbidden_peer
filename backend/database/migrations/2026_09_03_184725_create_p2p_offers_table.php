<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void 
    {
        Schema::create('p2p_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['buy', 'sell']); // Listing type
            $table->string('fiat_currency', 10);   // USD, EUR, KES, etc.
            
            $table->decimal('price', 18, 2);             // Price per 1 USDT in Fiat
            $table->decimal('total_amount', 18, 8);      // Initial USDT listed
            $table->decimal('remaining_amount', 18, 8);  // Unfilled USDT remaining
            $table->decimal('min_limit', 18, 2);         // Min fiat amount allowed per order
            $table->decimal('max_limit', 18, 2);         // Max fiat amount allowed per order
            
            $table->integer('payment_window_minutes')->default(15); // Expiration timer
            $table->enum('status', ['active', 'paused', 'completed', 'cancelled'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void 
    {
        Schema::dropIfExists('p2p_offers');
    }
};