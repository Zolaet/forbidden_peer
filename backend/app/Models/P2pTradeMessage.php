<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class P2pTradeMessage extends Model
{
    protected $fillable = [
        'trade_id',
        'sender_id',
        'message',
        'attachment_path',
        'is_proof_of_payment',
    ];

    protected $casts = [
        'is_proof_of_payment' => 'boolean',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(P2pTrade::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}