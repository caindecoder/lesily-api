<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * An authenticated user tried to consume an invitation addressed to a different
 * email (UC-1-019 alt-4b) — only the invited account may accept (R9/D7). 403
 * INVITE_EMAIL_MISMATCH; the invited address is not echoed back (the link
 * holder already sees it in the accept URL, but the envelope stays clean).
 */
class InviteEmailMismatchException extends Exception
{
    public function __construct()
    {
        parent::__construct('This invitation was issued to a different email address. Log in with the invited account to accept it.');
    }
}
