<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An outbound USDT (BEP-20) transfer request, auto-broadcast from the
 * treasury account.
 */
class Withdrawal extends Model
{
    public const REQUESTED = 'requested';
    public const BROADCASTING = 'broadcasting';
    public const SENT = 'sent';
    public const FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'network',
        'to_address',
        'amount',
        'fee',
        'net_amount',
        'client_ref',
        'tx_hash',
        'status',
        'error',
        'broadcast_at',
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'fee' => 'decimal:8',
        'net_amount' => 'decimal:8',
        'broadcast_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
