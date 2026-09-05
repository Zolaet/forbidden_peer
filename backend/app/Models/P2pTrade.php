<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class P2pTrade extends Model
{
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
        'expires_at',
    ];

    protected $casts = [
        'crypto_amount' => 'decimal:8',
        'fiat_amount' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
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
}