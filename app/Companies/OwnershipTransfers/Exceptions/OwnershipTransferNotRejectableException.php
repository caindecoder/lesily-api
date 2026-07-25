<?php

namespace App\Companies\OwnershipTransfers\Exceptions;

use Exception;

/**
 * T3/T5 was attempted from a state that cannot reach Rejected — an already-ended
 * transfer, whether rejected, cancelled, expired or executed (SM-1-05 §4: only
 * the two OPEN states are rejectable).
 *
 * Renders as the shared 409 STATE_GUARD_VIOLATION family
 * (OwnershipTransferNotVerifiableException precedent).
 */
class OwnershipTransferNotRejectableException extends Exception
{
    protected $message = 'Only an open transfer can be rejected.';
}
