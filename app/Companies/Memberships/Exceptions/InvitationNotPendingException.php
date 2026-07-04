<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * A resend was requested for an invitation that is no longer pending — already
 * accepted, expired, or (Task 5) revoked. ONE collapsed message + error_code
 * INVITE_NOT_PENDING (422) for every cause; admin-facing counterpart to the
 * accept path's INVITE_INVALID (s110 R3).
 */
class InvitationNotPendingException extends Exception
{
    public function __construct()
    {
        parent::__construct('This invitation is no longer pending.');
    }
}
