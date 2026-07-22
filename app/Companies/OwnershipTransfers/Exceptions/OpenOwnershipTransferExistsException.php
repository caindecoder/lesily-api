<?php

namespace App\Companies\OwnershipTransfers\Exceptions;

use Exception;

/**
 * A second transfer request while one is still open (requested|verified) —
 * one transfer at a time per company (ERD-1-05 §5 invariant; SM-1-05 T1
 * guard). Renders as the shared 409 STATE_GUARD_VIOLATION family
 * (ReceiptNotAvailableException precedent).
 */
class OpenOwnershipTransferExistsException extends Exception
{
    protected $message = 'An ownership transfer request is already open for this company. Withdraw it before requesting another.';
}
