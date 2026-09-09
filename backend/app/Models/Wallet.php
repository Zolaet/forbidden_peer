<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }

    /**
     * Append an audit row describing the change just made to this wallet.
     * Must be called inside the same DB transaction as the balance change.
     */
    protected function record(
        string $type,
        float $amountDelta,
        float $availableAfter,
        float $escrowAfter,
        ?Model $ref = null
    ): void {
        $this->transactions()->create([
            'user_id' => $this->user_id,
            'type' => $type,
            'amount' => $amountDelta,
            'balance_after' => $availableAfter,
            'escrow_after' => $escrowAfter,
            'ref_type' => $ref ? $ref->getMorphClass() : null,
            'ref_id' => $ref ? $ref->getKey() : null,
        ]);
    }

    /** Lock funds from available balance into escrow balance. */
    public function lockEscrow(float $amount, ?Model $ref = null): bool
    {
        return DB::transaction(function () use ($amount, $ref) {
            /** @var Wallet $wallet */
            $wallet = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($wallet->available_balance < $amount) {
                throw new Exception("Insufficient available balance to lock in escrow.");
            }

            $wallet->available_balance -= $amount;
            $wallet->escrow_balance += $amount;
            $wallet->save();

            $wallet->record(
                WalletTransaction::TYPE_TRADE_LOCK,
                -1 * $amount,
                (float) $wallet->available_balance,
                (float) $wallet->escrow_balance,
                $ref
            );

            return true;
        });
    }

    /** Complete trade: Release locked escrow to the buyer's available balance. */
    public function releaseEscrowTo(Wallet $buyerWallet, float $amount, ?Model $ref = null): bool
    {
        return DB::transaction(function () use ($buyerWallet, $amount, $ref) {
            /** @var Wallet $sellerWallet */
            $sellerWallet = static::where('id', $this->id)->lockForUpdate()->firstOrFail();
            /** @var Wallet $targetBuyerWallet */
            $targetBuyerWallet = static::where('id', $buyerWallet->id)->lockForUpdate()->firstOrFail();

            if ($sellerWallet->escrow_balance < $amount) {
                throw new Exception("Insufficient escrow balance to release.");
            }

            // Deduct from seller's escrow, add to buyer's available balance.
            $sellerWallet->escrow_balance -= $amount;
            $sellerWallet->save();

            $targetBuyerWallet->available_balance += $amount;
            $targetBuyerWallet->save();

            // Seller: escrow falls, available unchanged.
            $sellerWallet->record(
                WalletTransaction::TYPE_TRADE_RELEASE,
                0.0,
                (float) $sellerWallet->available_balance,
                (float) $sellerWallet->escrow_balance,
                $ref
            );

            // Buyer: available rises.
            $targetBuyerWallet->record(
                WalletTransaction::TYPE_TRADE_RELEASE,
                $amount,
                (float) $targetBuyerWallet->available_balance,
                (float) $targetBuyerWallet->escrow_balance,
                $ref
            );

            return true;
        });
    }

    /** Cancel trade / Dispute refund: Return escrow back to seller's available balance. */
    public function refundEscrow(float $amount, ?Model $ref = null): bool
    {
        return DB::transaction(function () use ($amount, $ref) {
            /** @var Wallet $wallet */
            $wallet = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($wallet->escrow_balance < $amount) {
                throw new Exception("Insufficient escrow balance to refund.");
            }

            $wallet->escrow_balance -= $amount;
            $wallet->available_balance += $amount;
            $wallet->save();

            $wallet->record(
                WalletTransaction::TYPE_TRADE_REFUND,
                $amount,
                (float) $wallet->available_balance,
                (float) $wallet->escrow_balance,
                $ref
            );

            return true;
        });
    }

    /**
     * Credit available balance (deposits, refunds, admin). Row-locked.
     */
    public function credit(float $amount, string $type = WalletTransaction::TYPE_ADMIN, ?Model $ref = null): bool
    {
        return DB::transaction(function () use ($amount, $type, $ref) {
            /** @var Wallet $wallet */
            $wallet = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $wallet->available_balance += $amount;
            $wallet->save();

            $wallet->record($type, $amount, (float) $wallet->available_balance, (float) $wallet->escrow_balance, $ref);

            return true;
        });
    }

    /**
     * Debit available balance (withdrawals, fees, admin). Row-locked.
     */
    public function debit(float $amount, string $type = WalletTransaction::TYPE_ADMIN, ?Model $ref = null): bool
    {
        return DB::transaction(function () use ($amount, $type, $ref) {
            /** @var Wallet $wallet */
            $wallet = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($wallet->available_balance < $amount) {
                throw new Exception("Insufficient available balance.");
            }

            $wallet->available_balance -= $amount;
            $wallet->save();

            $wallet->record($type, -1 * $amount, (float) $wallet->available_balance, (float) $wallet->escrow_balance, $ref);

            return true;
        });
    }
}
