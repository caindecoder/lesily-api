<?php

namespace App\Companies\Exceptions;

use Exception;

/**
 * A rejection may close an open OR a verified ownership-transfer case —
 * a case that is already closed refuses (UC-1-128 error flow 4a;
 * STATE_GUARD_VIOLATION 409, the closed state named).
 */
class OwnershipTransferCaseAlreadyClosedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Only an open or verified ownership-transfer case can be rejected; this case is closed.');
    }
}
