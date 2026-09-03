<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void 
    {
        Schema::create('p2p_trades', function (Blueprint $table) {
            $table->id();
            $table->string('trade_ref')->unique(); // e.g., 'TRD-9012384'
            
            $table->foreignId('offer_id')->constrained('p2p_offers');
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('seller_id')->constrained('users');
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            
            $table->decimal('crypto_amount', 18, 8);
            $table->decimal('fiat_amount', 18, 2);
            $table->decimal('unit_price', 18, 2);

            $table->enum('status', [
                'pending',    // Funds locked in escrow, waiting for buyer payment
                'paid',       // Buyer clicked "Paid" / uploaded proof
                'completed',  // Seller confirmed receipt and released funds
                'disputed',   // One party flagged for admin review
                'cancelled',  // Expired or buyer cancelled
                'refunded'    // Admin refunded escrow back to seller
            ])->default('pending');

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // Hard deadline for buyer to pay
            $table->timestamps();
        });
    }

    public function down(): void 
    {
        Schema::dropIfExists('p2p_trades');
    }
};