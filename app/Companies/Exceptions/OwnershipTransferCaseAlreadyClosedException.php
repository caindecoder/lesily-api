<?php

namespace App\Companies\Exceptions;

use Exception;
use App\Companies\Enums\OwnershipTransferCaseStatus;

/**
 * A rejection may close an open OR a verified ownership-transfer case —
 * a case in any other state refuses (UC-1-128 error flow 4a;
 * STATE_GUARD_VIOLATION 409, the case's current state named).
 */
class OwnershipTransferCaseAlreadyClosedException extends Exception
{
    public function __construct(OwnershipTransferCaseStatus $status)
    {
        parent::__construct('Only an open or verified ownership-transfer case can be rejected; this case is '.$status->value.'.');
    }
}
