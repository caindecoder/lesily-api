<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * An invite targeted an email that is already an active member of the company
 * (UC-1-018 3e). error_code ALREADY_MEMBER (422).
 */
class AlreadyMemberException extends Exception
{
    public function __construct()
    {
        parent::__construct('This user is already a member of this company.');
    }
}
