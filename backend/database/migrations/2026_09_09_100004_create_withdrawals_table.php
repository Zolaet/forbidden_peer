<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbound USDT (BEP-20) transfer requests. Auto-broadcast from the
     * treasury account the moment one is created. client_ref makes retries safe.
     */
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('network', 10);
            $table->string('to_address', 64);
            $table->decimal('amount', 18, 8);          // total debited from the user
            $table->decimal('fee', 18, 8)->default(0); // platform fee
            $table->decimal('net_amount', 18, 8);      // amount sent on chain (amount - fee)
            $table->string('client_ref', 64)->unique();
            $table->string('tx_hash', 66)->nullable();
            $table->enum('status', ['requested', 'broadcasting', 'sent', 'failed'])->default('requested');
            $table->text('error')->nullable();
            $table->timestamp('broadcast_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
