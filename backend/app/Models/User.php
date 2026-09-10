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

    public const ROLE_USER = 'user';
    public const ROLE_ADMIN = 'admin';

    /**
     * Deliberately absent: 'role'.
     *
     * A mass-assignable role column is a self-promotion bug waiting for the
     * first `User::create($request->validated())`. Registration happens to
     * build its array field-by-field today, but that is one refactor from
     * being untrue. Role changes go through `php artisan user:promote`, which
     * force-fills the column explicitly.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** True for platform staff who can adjudicate disputes. */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    // --- P2P Relationships ---

    /**
     * User's main cryptocurrency wallet.
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * User's saved payment methods for accepting fiat.
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /**
     * The user's deposit addresses (one per network/currency).
     */
    public function cryptoAddresses(): HasMany
    {
        return $this->hasMany(CryptoAddress::class);
    }

    /**
     * Inbound BEP-20 deposits detected for this user.
     */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    /**
     * Outbound BEP-20 withdrawal requests for this user.
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    /**
     * Internal balance audit ledger for this user.
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Marketplace buy/sell offers posted by the user.
     */
    public function offers(): HasMany
    {
        return $this->hasMany(P2pOffer::class);
    }

    /**
     * Trades where the user is buying crypto.
     */
    public function tradesAsBuyer(): HasMany
    {
        return $this->hasMany(P2pTrade::class, 'buyer_id');
    }

    /**
     * Trades where the user is selling crypto.
     */
    public function tradesAsSeller(): HasMany
    {
        return $this->hasMany(P2pTrade::class, 'seller_id');
    }
}