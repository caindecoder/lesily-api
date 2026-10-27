<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * A bulk invite asked for more invitations than the actor has left this hour
 * (UC-1-020 2b). Maps to error_code RATE_LIMIT_EXCEEDED (429) with a Retry-After
 * header, as the throttle middleware answers a single invite.
 */
class InvitationAllowanceExceededException extends Exception
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many requests. Please try again later.');
    }
}
