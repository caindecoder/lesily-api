<?php

namespace App\Companies\OwnershipTransfers\Exceptions;

use Exception;

/**
 * The caller holds `ownership-transfer.withdraw` but is not the transfer's
 * recorded requester (`current_owner_user_id`) — UC-1-114/115 error-flow 3a.
 *
 * Renders as 403 TRANSFER_REQUESTER_MISMATCH, deliberately NOT the
 * ROLE_INSUFFICIENT the permission middleware emits: that code means the caller
 * lacks the role, and this one holds it and is refused on identity. A client
 * that cannot tell the two apart cannot tell a denied target user from an owner
 * acting on someone else's request (ruling J3).
 */
class NotTheTransferRequesterException extends Exception
{
    protected $message = 'Only the member who requested this ownership transfer can withdraw it.';
}
