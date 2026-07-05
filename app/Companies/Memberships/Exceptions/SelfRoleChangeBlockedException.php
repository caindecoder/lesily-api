<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * An actor tried to change their OWN role — rejected to prevent accidental
 * privilege loss/escalation; another Owner/Admin must make the change. ONE
 * code + message, SELF_ROLE_CHANGE_BLOCKED (422) (R-B session 112; UC-1-023
 * error flow 4d).
 */
class SelfRoleChangeBlockedException extends Exception
{
    public function __construct()
    {
        parent::__construct('You cannot change your own role. Ask another Owner/Admin.');
    }
}
