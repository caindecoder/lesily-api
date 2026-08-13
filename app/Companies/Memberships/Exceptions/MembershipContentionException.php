<?php

namespace App\Companies\Memberships\Exceptions;

use Exception;
use Throwable;

/**
 * A membership write's locked transaction lost to concurrent lock contention —
 * MySQL 1205 lock-wait timeout or 1213 deadlock (OQ-1-164 F3 extension: the
 * role-change and removal interactors lock the same pivot rows the accept
 * path locks; the world moved, 409 STATE_GUARD_VIOLATION, the client retries).
 */
class MembershipContentionException extends Exception
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The change lost to a concurrent membership update. Please retry.', 0, $previous);
    }
}
