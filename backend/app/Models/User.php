<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Every user automatically gets a USDT wallet
    public function wallet(): HasOne
    {
        return $table = $this->hasOne(Wallet::class)->where('currency', 'USDT');
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(P2pOffer::class);
    }

    public function buyTrades(): HasMany
    {
        return $this->hasMany(P2pTrade::class, 'buyer_id');
    }

    public function sellTrades(): HasMany
    {
        return $this->hasMany(P2pTrade::class, 'seller_id');
    }
}