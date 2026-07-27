<?php

namespace App\Companies\OwnershipTransfers\Exceptions;

use Exception;

/**
 * T6/T7 was attempted from a state that cannot reach Cancelled — an
 * already-ended transfer, whether rejected, cancelled, expired or executed
 * (SM-1-05 §4: only the two OPEN states are withdrawable).
 *
 * Renders as the shared 409 STATE_GUARD_VIOLATION family
 * (OwnershipTransferNotRejectableException precedent).
 */
class OwnershipTransferNotWithdrawableException extends Exception
{
    protected $message = 'Only an open transfer can be withdrawn.';
}
