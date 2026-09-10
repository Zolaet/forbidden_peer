<?php

namespace App\Services\Bsc\Exceptions;

use RuntimeException;

/**
 * A node answered with a JSON-RPC/HTTP error instead of accepting the bytes.
 *
 * It means *this node* did not take the transaction — not that nothing was
 * broadcast. sendRaw stops at the first node that answers, so a rejection can
 * be one lagging or confused endpoint talking, and wordings like "nonce too
 * low" or "already known" usually mean our own earlier attempt is already on
 * chain. Classify the message (see WithdrawalService::classifyRejection) before
 * concluding anything, and never re-credit on this exception alone.
 */
class RpcRejectedException extends RuntimeException
{
}
