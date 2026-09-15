<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identify a deposit by the log, not by the transaction.
 *
 * tx_hash was unique, but a single BSC transaction can emit any number of
 * BEP-20 Transfer events — a batch payout, a multicall, or a contract paying
 * several recipients at once. Keying on the hash alone meant the indexer
 * recorded only the first such transfer and silently discarded the rest, so
 * every other recipient in that transaction was never credited, with nothing
 * in the logs to say a deposit had been dropped.
 *
 * (tx_hash, log_index) is the pair that actually identifies one Transfer log,
 * and it is what DepositIndexer::ingestLog() now keys its firstOrCreate on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            // Existing rows all predate this and represent the first recorded
            // transfer of their transaction, which is log index 0 — the default
            // backfills them correctly.
            $table->unsignedInteger('log_index')->default(0)->after('tx_hash');
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->dropUnique(['tx_hash']);
            $table->unique(['tx_hash', 'log_index']);
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropUnique(['tx_hash', 'log_index']);
            $table->unique('tx_hash');
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn('log_index');
        });
    }
};
