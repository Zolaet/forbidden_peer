<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's stable, unique deposit address on a given chain network.
 */
class CryptoAddress extends Model
{
    protected $fillable = [
        'user_id',
        'network',
        'currency',
        'address',
        'derivation_index',
    ];

    protected $casts = [
        'derivation_index' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
