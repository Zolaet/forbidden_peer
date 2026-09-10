<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A balance movement was refused because the wallet did not hold enough.
 *
 * Distinct from a database failure so callers can tell "the user cannot afford
 * this" (a 4xx the client can act on) from "something broke" — the withdrawal
 * endpoint maps this to 409 and everything else to a 500-level error.
 */
class InsufficientBalanceException extends RuntimeException
{
}
