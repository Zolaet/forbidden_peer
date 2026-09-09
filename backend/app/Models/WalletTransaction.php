<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row per internal balance movement, written inside the same transaction
 * as the movement it records. balance_after / escrow_after snapshot the wallet
 * so any point in history is reconstructible.
 */
class WalletTransaction extends Model
{
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_WITHDRAW = 'withdraw';
    public const TYPE_FEE = 'fee';
    public const TYPE_TRADE_LOCK = 'trade_lock';
    public const TYPE_TRADE_RELEASE = 'trade_release';
    public const TYPE_TRADE_REFUND = 'trade_refund';
    public const TYPE_ADMIN = 'admin';

    protected $fillable = [
        'user_id',
        'wallet_id',
        'type',
        'ref_type',
        'ref_id',
        'amount',
        'balance_after',
        'escrow_after',
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'balance_after' => 'decimal:8',
        'escrow_after' => 'decimal:8',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function ref(): MorphTo
    {
        return $this->morphTo();
    }
}
