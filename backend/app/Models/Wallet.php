<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'currency',
        'available_balance',
        'escrow_balance',
    ];

    protected $casts = [
        'available_balance' => 'decimal:8',
        'escrow_balance' => 'decimal:8',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Lock funds from available balance into escrow balance.
     */
    public function lockEscrow(float $amount): bool
    {
        return DB::transaction(function () use ($amount) {
            // Lock the row for update to prevent race conditions
            $wallet = static::where('id', $this->id)->lockForUpdate()->first();

            if ($wallet->available_balance < $amount) {
                throw new Exception("Insufficient available balance to lock in escrow.");
            }

            $wallet->available_balance -= $amount;
            $wallet->escrow_balance += $amount;
            
            return $wallet->save();
        });
    }

    /**
     * Complete trade: Release locked escrow to the buyer's available balance.
     */
    public function releaseEscrowTo(Wallet $buyerWallet, float $amount): bool
    {
        return DB::transaction(function () use ($buyerWallet, $amount) {
            $sellerWallet = static::where('id', $this->id)->lockForUpdate()->first();
            $targetBuyerWallet = static::where('id', $buyerWallet->id)->lockForUpdate()->first();

            if ($sellerWallet->escrow_balance < $amount) {
                throw new Exception("Insufficient escrow balance to release.");
            }

            // Deduct from seller's escrow, add to buyer's available balance
            $sellerWallet->escrow_balance -= $amount;
            $targetBuyerWallet->available_balance += $amount;

            $sellerWallet->save();
            $targetBuyerWallet->save();

            return true;
        });
    }

    /**
     * Cancel trade / Dispute refund: Return escrow back to seller's available balance.
     */
    public function refundEscrow(float $amount): bool
    {
        return DB::transaction(function () use ($amount) {
            $wallet = static::where('id', $this->id)->lockForUpdate()->first();

            if ($wallet->escrow_balance < $amount) {
                throw new Exception("Insufficient escrow balance to refund.");
            }

            $wallet->escrow_balance -= $amount;
            $wallet->available_balance += $amount;

            return $wallet->save();
        });
    }
}