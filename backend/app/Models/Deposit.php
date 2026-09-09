<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An inbound USDT (BEP-20) transfer detected on a user's deposit address.
 */
class Deposit extends Model
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';

    protected $fillable = [
        'user_id',
        'network',
        'deposit_address',
        'from_address',
        'tx_hash',
        'block_number',
        'amount',
        'value_raw',
        'confirmations',
        'status',
        'detected_at',
        'credited_at',
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'block_number' => 'integer',
        'confirmations' => 'integer',
        'detected_at' => 'datetime',
        'credited_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
