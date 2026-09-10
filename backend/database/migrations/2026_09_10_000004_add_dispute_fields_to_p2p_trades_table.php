<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail for a disputed trade.
 *
 * `disputed` and `refunded` have been in the p2p_trades status enum since the
 * table was created, with nothing in the codebase ever assigning them. These
 * columns are what make those states meaningful: who flagged it, why, who
 * ruled, and on what reasoning.
 *
 * No new index. The admin queue filters on `status`, and the composite
 * (status, expires_at) index added in 2026_09_10_000001 already serves that as
 * a prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('p2p_trades', function (Blueprint $table) {
            $table->timestamp('disputed_at')->nullable()->after('cancel_reason');

            // Plain indexed columns rather than foreignId()->constrained().
            // Adding a FK constraint to an *existing* table is a table rebuild
            // on SQLite, and this migration runs on every test boot — a
            // failure here would take the whole suite down, not just these
            // tests. Both values are always set from an authenticated user, so
            // the constraint is enforced in the application either way.
            // Nullable because the dispute record must outlive the account that
            // raised it, or the money movement becomes unexplainable.
            $table->unsignedBigInteger('disputed_by')->nullable()->after('disputed_at')->index();
            $table->string('dispute_reason', 255)->nullable()->after('disputed_by');

            $table->timestamp('resolved_at')->nullable()->after('dispute_reason');
            $table->unsignedBigInteger('resolved_by')->nullable()->after('resolved_at')->index();
            $table->string('resolution_note', 255)->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('p2p_trades', function (Blueprint $table) {
            $table->dropIndex(['disputed_by']);
            $table->dropIndex(['resolved_by']);
            $table->dropColumn([
                'disputed_at',
                'disputed_by',
                'dispute_reason',
                'resolved_at',
                'resolved_by',
                'resolution_note',
            ]);
        });
    }
};
