<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;

/**
 * A role change targeted the Owner's membership — the Owner's role can only
 * change through the ownership-transfer flow (UC-1-028 → UC-1-093), never via
 * PATCH /memberships/{id}. ONE code + message, OWNER_TRANSFER_REQUIRED (422)
 * (R-A session 112; §S10b strictly-one-owner).
 */
class OwnerTransferRequiredException extends Exception
{
    public function __construct()
    {
        parent::__construct("The Owner's role can only be changed through an ownership transfer.");
    }
}
