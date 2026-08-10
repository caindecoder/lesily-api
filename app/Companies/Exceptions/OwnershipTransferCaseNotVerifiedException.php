<?php

namespace App\Companies\Exceptions;

use Exception;
use App\Companies\Enums\OwnershipTransferCaseStatus;

/**
 * Only a verified ownership-transfer case may be executed (UC-1-093 error
 * flow 3a; STATE_GUARD_VIOLATION 409, the case's current state named).
 */
class OwnershipTransferCaseNotVerifiedException extends Exception
{
    public function __construct(OwnershipTransferCaseStatus $status)
    {
        parent::__construct('Only a verified ownership-transfer case can be executed; this case is '.$status->value.'.');
    }
}
