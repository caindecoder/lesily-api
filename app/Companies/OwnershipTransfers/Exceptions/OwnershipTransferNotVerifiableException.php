<?php

namespace App\Companies\OwnershipTransfers\Exceptions;

use Exception;

/**
 * T2 was attempted from a state that cannot reach Verified — an already-verified
 * transfer, or any terminal one (SM-1-05 §4: Requested is T2's only source).
 * Renders as the shared 409 STATE_GUARD_VIOLATION family
 * (OpenOwnershipTransferExistsException precedent).
 */
class OwnershipTransferNotVerifiableException extends Exception
{
    protected $message = 'Only a transfer awaiting verification can be verified.';
}
