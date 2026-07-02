<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * An invite targeted an email that is already an active member of the company,
 * or one that already holds a live pending invitation (UC-1-018 3e; api-1-users
 * "active invite/member"). error_code ALREADY_MEMBER (422) for both.
 */
class AlreadyMemberException extends Exception
{
    public function __construct(string $message = 'This user is already a member of this company.')
    {
        parent::__construct($message);
    }

    public static function pendingInvitation(): self
    {
        return new self('This email has already been invited to this company.');
    }
}
