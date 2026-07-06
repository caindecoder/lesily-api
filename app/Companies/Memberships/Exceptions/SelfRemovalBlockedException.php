<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * An actor tried to remove their OWN membership — rejected; another Owner/Admin
 * must remove them. ONE code + message, SELF_REMOVAL_BLOCKED (422) (R-B session
 * 113; UC-1-024 error flow 2b).
 */
class SelfRemovalBlockedException extends Exception
{
    public function __construct()
    {
        parent::__construct('You cannot remove yourself. Ask another Owner/Admin.');
    }
}
