<?php

namespace App\Services\Bsc\Exceptions;

use RuntimeException;

/**
 * Every RPC node was unreachable / timed out / returned a 5xx — the outcome of
 * a broadcast is UNKNOWN. Must NOT re-credit automatically.
 */
class RpcTransportException extends RuntimeException
{
}
