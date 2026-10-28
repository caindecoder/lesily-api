<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * An invite, resend or bulk batch asked for more invitations than the actor has left
 * this hour (UC-1-018, UC-1-020 2b, UC-1-021). Maps to error_code RATE_LIMIT_EXCEEDED
 * (429) with Retry-After and X-RateLimit-Reset, as every other 429 answers.
 */
class InvitationAllowanceExceededException extends Exception
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many requests. Please try again later.');
    }
}
