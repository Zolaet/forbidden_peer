<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two things the withdrawal row was missing.
     *
     * raw_tx / nonce: the signed transaction is kept so a retry re-broadcasts
     * the *same bytes* rather than signing a new transaction. A re-signed tx
     * has a different hash for the same nonce, which is how a retry can
     * silently become a second payout.
     *
     * needs_review: a broadcast whose outcome we genuinely cannot determine.
     * It used to share the `failed` status with a definite rejection — but a
     * definite rejection has been re-credited and a maybe-sent one must never
     * be, so the two cannot look alike to an operator.
     */
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->text('raw_tx')->nullable()->after('tx_hash');
            $table->unsignedBigInteger('nonce')->nullable()->after('raw_tx');
            $table->enum('status', ['requested', 'broadcasting', 'sent', 'failed', 'needs_review'])
                ->default('requested')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropColumn(['raw_tx', 'nonce']);
            $table->enum('status', ['requested', 'broadcasting', 'sent', 'failed'])
                ->default('requested')
                ->change();
        });
    }
};
