<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class P2pTrade extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    /** Statuses where USDT is sitting in escrow. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_DISPUTED];

    /**
     * Statuses an administrator may adjudicate.
     *
     * Deliberately excludes PENDING: nothing is owed on an unpaid order, and
     * the seller-cancel path and the `trades:expire` sweep already close those.
     * Also excludes COMPLETED, where the escrow has left the platform and the
     * buyer may have already withdrawn it — reversing that would debit a
     * possibly-empty wallet.
     */
    public const RESOLVABLE_STATUSES = [self::STATUS_PAID, self::STATUS_DISPUTED];

    protected $fillable = [
        'trade_ref',
        'offer_id',
        'buyer_id',
        'seller_id',
        'payment_method_id',
        'crypto_amount',
        'fiat_amount',
        'unit_price',
        'status',
        'paid_at',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
        'disputed_at',
        'disputed_by',
        'dispute_reason',
        'resolved_at',
        'resolved_by',
        'resolution_note',
        'expires_at',
    ];

    protected $casts = [
        'crypto_amount' => 'decimal:8',
        'fiat_amount' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'disputed_at' => 'datetime',
        'disputed_by' => 'integer',
        'resolved_at' => 'datetime',
        'resolved_by' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(P2pOffer::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(P2pTradeMessage::class, 'trade_id');
    }

    /**
     * True once the buyer's payment window has closed. An unpaid trade past
     * this point is cancelled by the `trades:expire` sweep, which returns the
     * escrow to the seller.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Unpaid trades whose payment window has closed. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * The party who must move next, from the perspective of a participant.
     *
     * Crypto direction is a property of the ad, not of who tapped it: on a
     * 'sell' ad the offer owner is the crypto seller, on a 'buy' ad the owner
     * is the crypto buyer and the taker is the one selling.
     */
    public function roleOf(User $user): ?string
    {
        if ((int) $this->buyer_id === (int) $user->id) {
            return 'buyer';
        }

        if ((int) $this->seller_id === (int) $user->id) {
            return 'seller';
        }

        return null;
    }
}
