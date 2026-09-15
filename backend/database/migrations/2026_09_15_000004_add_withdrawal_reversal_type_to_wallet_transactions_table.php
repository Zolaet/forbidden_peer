<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give a re-credited withdrawal its own ledger type.
 *
 * When a withdrawal fails definitively, WithdrawalService::failAndRecredit()
 * puts the debited funds back. It recorded that credit as `admin`, which is the
 * type for a manual operator adjustment — so the ledger could not distinguish
 * "our broadcast was rejected and we reversed ourselves" from "a human moved
 * this money by hand". Reconstructing why a balance changed meant inferring it
 * from the ref_type/ref_id instead of reading the type.
 *
 * Reuses the same comment style as the original table migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->enum('type', [
                'deposit',              // + available   (on-chain USDT credited)
                'withdraw',             // - available   (USDT sent out)
                'fee',                  // - available   (withdrawal fee)
                'trade_lock',           // available -> escrow
                'trade_release',        // escrow -> buyer available
                'trade_refund',         // escrow -> seller available (cancel/dispute)
                'withdrawal_reversal',  // + available   (failed withdrawal put back)
                'admin',                // manual operator adjustment
            ])->default('admin')->change();
        });
    }

    public function down(): void
    {
        // Rows already written as withdrawal_reversal would be truncated to ''
        // by a strict-mode ALTER, so they are folded back into 'admin' — the
        // type they carried before this migration — first.
        DB::table('wallet_transactions')
            ->where('type', 'withdrawal_reversal')
            ->update(['type' => 'admin']);

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->enum('type', [
                'deposit',
                'withdraw',
                'fee',
                'trade_lock',
                'trade_release',
                'trade_refund',
                'admin',
            ])->default('admin')->change();
        });
    }
};
