<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Resume point for the deposit indexer on one network.
 */
class ChainScanState extends Model
{
    protected $table = 'chain_scan_state';

    protected $fillable = ['network', 'last_block'];

    protected $casts = [
        'last_block' => 'integer',
    ];
}
