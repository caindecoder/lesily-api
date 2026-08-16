<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;
use Throwable;

/**
 * The accept's locked transaction lost to concurrent lock contention —
 * MySQL 1205 lock-wait timeout or 1213 deadlock (OQ-1-164, extending the
 * s170 ruling: the world moved, 409 STATE_GUARD_VIOLATION; the same link
 * simply retries).
 */
class InvitationAcceptContentionException extends Exception
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Your invitation is being processed by another request. Please retry.', 0, $previous);
    }
}
