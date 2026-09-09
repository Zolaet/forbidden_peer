<?php

namespace App\Services\Bsc\Exceptions;

use RuntimeException;

/**
 * The node answered with a JSON-RPC/HTTP error — the transaction was NOT
 * accepted, so the state change is definitive. (e.g. "nonce too low",
 * "insufficient funds", 4xx rejection). Safe to re-credit.
 */
class RpcRejectedException extends RuntimeException
{
}
