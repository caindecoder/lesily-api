<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * The accept token did not resolve to a live pending invitation — never issued,
 * expired, already used, or revoked (H2, joined Task 5). ONE collapsed message + error_code
 * INVITE_INVALID (422) for every cause, so a probe cannot learn token state
 * (H4 session 109; RESET_LINK_INVALID parity).
 */
class InvalidInvitationException extends Exception
{
    public function __construct()
    {
        parent::__construct('This invitation link is invalid or has expired. Ask your admin to send a new one.');
    }
}
