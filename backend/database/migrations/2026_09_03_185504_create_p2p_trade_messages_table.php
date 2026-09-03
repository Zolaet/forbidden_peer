<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void 
    {
        Schema::create('p2p_trade_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('p2p_trades')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->text('message')->nullable();
            $table->string('attachment_path')->nullable(); // Image upload path
            $table->boolean('is_proof_of_payment')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void 
    {
        Schema::dropIfExists('p2p_trade_messages');
    }
};