<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inbound USDT (BEP-20) transfers the indexer saw land on a user's
     * deposit address. tx_hash is unique so re-scans can never double-credit.
     */
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('network', 10);
            $table->string('deposit_address', 64);
            $table->string('from_address', 64)->nullable();
            $table->string('tx_hash', 66)->unique();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->decimal('amount', 18, 8)->default(0);   // credited amount in USDT (8 dp)
            $table->string('value_raw', 80)->nullable();    // exact token value in wei (string math)
            $table->unsignedInteger('confirmations')->default(0);
            $table->enum('status', ['pending', 'confirmed'])->default('pending');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
