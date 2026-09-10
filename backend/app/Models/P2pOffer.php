<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class P2pOffer extends Model
{
    public const TYPE_BUY = 'buy';
    public const TYPE_SELL = 'sell';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'type',
        'fiat_currency',
        'price',
        'total_amount',
        'remaining_amount',
        'min_limit',
        'max_limit',
        'payment_window_minutes',
        'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'total_amount' => 'decimal:8',
        'remaining_amount' => 'decimal:8',
        'min_limit' => 'decimal:2',
        'max_limit' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(P2pTrade::class, 'offer_id');
    }

    /**
     * True when the ad's owner is the one selling crypto.
     *
     * A 'sell' ad is a crypto seller advertising, so a taker buys from them and
     * the owner's balance backs the escrow. A 'buy' ad is a crypto buyer
     * advertising, so the taker is the one with USDT to lock.
     */
    public function ownerSellsCrypto(): bool
    {
        return $this->type === self::TYPE_SELL;
    }
}
