<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * An actor tried to remove the company Owner — the Owner is immutable and can
 * only leave via ownership transfer (R-G/R-A session 113; UC-1-024 error flow
 * 2a). Rendered with the reused OWNER_TRANSFER_REQUIRED (422) code — the same
 * remedy as the change-role sibling — but a removal-specific message.
 */
class OwnerRemovalBlockedException extends Exception
{
    public function __construct()
    {
        parent::__construct('The company owner cannot be removed. Transfer ownership first.');
    }
}
