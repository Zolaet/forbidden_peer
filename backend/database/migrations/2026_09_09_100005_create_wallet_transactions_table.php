<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit ledger for every internal wallet balance movement. Rows are
     * written inside the same transaction as the balance change they describe.
     * amount is signed (+ credit, - debit); balance_after snapshots the wallet.
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'deposit',        // + available   (on-chain USDT credited)
                'withdraw',       // - available   (USDT sent out)
                'fee',            // - available   (withdrawal fee)
                'trade_lock',     // available -> escrow
                'trade_release',  // escrow -> buyer available
                'trade_refund',   // escrow -> seller available (cancel/dispute)
                'admin',          // manual operator adjustment
            ])->default('admin');
            $table->nullableMorphs('ref');      // deposit / withdrawal / p2p_trade
            $table->decimal('amount', 18, 8)->default(0); // signed delta of available_balance
            $table->decimal('balance_after', 18, 8)->default(0); // available snapshot
            $table->decimal('escrow_after', 18, 8)->default(0);  // escrow snapshot
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
