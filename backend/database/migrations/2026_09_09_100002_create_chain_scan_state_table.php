<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resume point for the deposit indexer (one row per network).
     */
    public function up(): void
    {
        Schema::create('chain_scan_state', function (Blueprint $table) {
            $table->id();
            $table->string('network', 10)->unique();
            $table->unsignedBigInteger('last_block')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chain_scan_state');
    }
};
