<?php

namespace App\Companies\Exceptions;

use Exception;
use App\Companies\Enums\OwnershipTransferCaseStatus;

/**
 * Only an open ownership-transfer case may be verified (UC-1-113 error flow
 * 4a; STATE_GUARD_VIOLATION 409, the case's current state named).
 */
class OwnershipTransferCaseNotOpenException extends Exception
{
    public function __construct(OwnershipTransferCaseStatus $status)
    {
        parent::__construct('Only an open ownership-transfer case can be verified; this case is '.$status->value.'.');
    }
}
