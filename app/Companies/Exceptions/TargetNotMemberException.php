<?php

namespace App\Companies\Exceptions;

use Exception;

/**
 * The named transfer target holds no live membership in this company — an
 * unaccepted invitation, a removed membership, or no tie at all (UC-1-028
 * error flow 3a; TARGET_NOT_MEMBER 422).
 */
class TargetNotMemberException extends Exception
{
    public function __construct()
    {
        parent::__construct('The transfer target must be an active member of this company.');
    }
}
