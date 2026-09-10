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

    /**
     * A broadcast whose outcome we could not determine.
     *
     * Kept separate from FAILED on purpose: FAILED means the node definitively
     * rejected the transaction and the user's balance has been re-credited.
     * A needs_review row may well be on chain, so re-crediting it would hand
     * the user their money twice — it has to be looked at by a human instead.
     */
    public const NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'user_id',
        'network',
        'to_address',
        'amount',
        'fee',
        'net_amount',
        'client_ref',
        'tx_hash',
        'raw_tx',
        'nonce',
        'status',
        'error',
        'broadcast_at',
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'fee' => 'decimal:8',
        'net_amount' => 'decimal:8',
        'nonce' => 'integer',
        'broadcast_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
