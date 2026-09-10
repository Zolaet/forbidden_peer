<?php

namespace App\Models;

use App\Exceptions\InsufficientBalanceException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A user's USDT balance: what they can spend, and what is held in escrow.
 *
 * Every amount crossing this boundary is a decimal *string* (see Money) —
 * never a PHP float. The columns are decimal(18,8); a float cannot represent
 * those exactly and `+=` on a balance drifts, so the ledger would slowly stop
 * reconciling with the trades behind it.
 */
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
     * A user's wallet row, minted on demand and then locked for update.
     *
     * `firstOrFail()` on the relation alone 404s for anyone who has never held
     * USDT — a perfectly normal state for the taker on a 'buy' ad, who is the
     * crypto seller in that trade and whose balance is what gets escrowed.
     */
    public static function lockedFor(int $userId, string $currency = 'USDT'): self
    {
        // firstOrCreate is safe here: the (user_id, currency) unique index makes
        // a concurrent create lose cleanly and re-read the winner's row.
        $wallet = static::firstOrCreate(
            ['user_id' => $userId, 'currency' => $currency],
            ['available_balance' => 0, 'escrow_balance' => 0]
        );

        return static::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Append an audit row describing the change just made to this wallet.
     * Must be called inside the same DB transaction as the balance change.
     */
    protected function record(
        string $type,
        string $amountDelta,
        string $availableAfter,
        string $escrowAfter,
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
    public function lockEscrow(string $amount, ?Model $ref = null): bool
    {
        $amount = Money::of($amount);
        $this->assertPositive($amount, 'lock in escrow');

        return DB::transaction(function () use ($amount, $ref) {
            /** @var Wallet $wallet */
            $wallet = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if (Money::lt($wallet->available_balance, $amount)) {
                throw new InsufficientBalanceException('Insufficient available balance to lock in escrow.');
            }

            $wallet->available_balance = Money::sub($wallet->available_balance, $amount);
            $wallet->escrow_balance = Money::add($wallet->escrow_balance, $amount);
            $wallet->save();

            $wallet->record(
                WalletTransaction::TYPE_TRADE_LOCK,
                Money::negate($amount),
                $wallet->available_balance,
                $wallet->escrow_balance,
                $ref
            );

            return true;
        });
    }

    /** Complete trade: Release locked escrow to the buyer's available balance. */
    public function releaseEscrowTo(Wallet $buyerWallet, string $amount, ?Model $ref = null): bool
    {
        $amount = Money::of($amount);
        $this->assertPositive($amount, 'release');

        return DB::transaction(function () use ($buyerWallet, $amount, $ref) {
            // Lock both rows in ascending id order. Two trades between the same
            // pair of users running at once would otherwise be able to take the
            // same two rows in opposite orders and deadlock.
            $ids = array_values(array_unique([$this->id, $buyerWallet->id]));
            sort($ids);

            $locked = static::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            /** @var Wallet $sellerWallet */
            $sellerWallet = $locked[$this->id];
            /** @var Wallet $targetBuyerWallet */
            $targetBuyerWallet = $locked[$buyerWallet->id];

            if (Money::lt($sellerWallet->escrow_balance, $amount)) {
                throw new InsufficientBalanceException('Insufficient escrow balance to release.');
            }

            // Deduct from seller's escrow, add to buyer's available balance.
            $sellerWallet->escrow_balance = Money::sub($sellerWallet->escrow_balance, $amount);
            $sellerWallet->save();

            $targetBuyerWallet->available_balance = Money::add($targetBuyerWallet->available_balance, $amount);
            $targetBuyerWallet->save();

            // Seller: escrow falls, available unchanged.
            $sellerWallet->record(
                WalletTransaction::TYPE_TRADE_RELEASE,
                Money::zero(),
                $sellerWallet->available_balance,
                $sellerWallet->escrow_balance,
                $ref
            );

            // Buyer: available rises.
            $targetBuyerWallet->record(
                WalletTransaction::TYPE_TRADE_RELEASE,
                $amount,
                $targetBuyerWallet->available_balance,
                $targetBuyerWallet->escrow_balance,
                $ref
            );

            return true;
        });
    }

    /** Cancel trade / dispute refund: Return escrow back to the holder's available balance. */
    public function refundEscrow(string $amount, ?Model $ref = null): bool
    {
        $amount = Money::of($amount);
        $this->assertPositive($amount, 'refund');

        return DB::transaction(function () use ($amount, $ref) {
            /** @var Wallet $wallet */
            $wallet = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if (Money::lt($wallet->escrow_balance, $amount)) {
                throw new InsufficientBalanceException('Insufficient escrow balance to refund.');
            }

            $wallet->escrow_balance = Money::sub($wallet->escrow_balance, $amount);
            $wallet->available_balance = Money::add($wallet->available_balance, $amount);
            $wallet->save();

            $wallet->record(
                WalletTransaction::TYPE_TRADE_REFUND,
                $amount,
                $wallet->available_balance,
                $wallet->escrow_balance,
                $ref
            );

            return true;
        });
    }

    /**
     * Credit available balance (deposits, refunds, admin). Row-locked.
     */
    public function credit(string $amount, string $type = WalletTransaction::TYPE_ADMIN, ?Model $ref = null): bool
    {
        $amount = Money::of($amount);
        $this->assertPositive($amount, 'credit');

        return DB::transaction(function () use ($amount, $type, $ref) {
            /** @var Wallet $wallet */
            $wallet = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $wallet->available_balance = Money::add($wallet->available_balance, $amount);
            $wallet->save();

            $wallet->record($type, $amount, $wallet->available_balance, $wallet->escrow_balance, $ref);

            return true;
        });
    }

    /**
     * Debit available balance (withdrawals, fees, admin). Row-locked.
     */
    public function debit(string $amount, string $type = WalletTransaction::TYPE_ADMIN, ?Model $ref = null): bool
    {
        $amount = Money::of($amount);
        $this->assertPositive($amount, 'debit');

        return DB::transaction(function () use ($amount, $type, $ref) {
            /** @var Wallet $wallet */
            $wallet = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if (Money::lt($wallet->available_balance, $amount)) {
                throw new InsufficientBalanceException('Insufficient available balance.');
            }

            $wallet->available_balance = Money::sub($wallet->available_balance, $amount);
            $wallet->save();

            $wallet->record($type, Money::negate($amount), $wallet->available_balance, $wallet->escrow_balance, $ref);

            return true;
        });
    }

    /**
     * A zero or negative amount here is always a bug upstream, and moving it
     * would move the balance the wrong way, so refuse it instead.
     */
    protected function assertPositive(string $amount, string $operation): void
    {
        if (!Money::isPositive($amount)) {
            throw new \InvalidArgumentException(
                'Refusing to ' . $operation . ' a non-positive amount (' . $amount . ').'
            );
        }
    }
}
