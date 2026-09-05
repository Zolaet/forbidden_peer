<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class P2pOffer extends Model
{
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
}