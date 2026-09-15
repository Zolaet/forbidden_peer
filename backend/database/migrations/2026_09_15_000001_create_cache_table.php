<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database cache store, plus its lock table.
 *
 * CACHE_STORE=database in .env, and every scheduled command in
 * routes/console.php uses ->withoutOverlapping(). That mutex is a cache lock:
 * CacheEventMutex asks the store whether it implements LockProvider, and
 * DatabaseStore does — so the schedule entry writes to `cache_locks` before it
 * starts. Without this table each of the three sweeps throws as it begins and
 * its body never runs, which means expired trades never refund escrow,
 * deposits are never credited and withdrawals are never broadcast.
 *
 * The locks table also backs the rate limiters in AppServiceProvider, so the
 * API cannot answer a request at all without it.
 *
 * The schema is the framework's own (php artisan make:cache-table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
