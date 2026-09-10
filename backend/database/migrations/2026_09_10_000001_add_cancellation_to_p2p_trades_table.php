<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A trade that is never paid for has to be able to end: the buyer's window
     * closes, the sweep cancels the row and the escrow goes back to the seller.
     * Without cancelled_at / cancel_reason there is no record of why USDT moved
     * back, which makes a later dispute impossible to reconstruct.
     */
    public function up(): void
    {
        Schema::table('p2p_trades', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_at');

            // Drives the expiry sweep: unpaid trades past their window.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('p2p_trades', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
