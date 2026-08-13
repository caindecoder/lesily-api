<?php

namespace App\Companies\Exceptions;

use Exception;
use Throwable;

/**
 * The transfer's locked transaction lost to concurrent lock contention —
 * MySQL 1205 lock-wait timeout or 1213 deadlock (ruled s170: the world moved,
 * 409 STATE_GUARD_VIOLATION; attempts=1 stands, the client retries).
 */
class OwnershipTransferContentionException extends Exception
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The transfer lost to a concurrent change. Please retry.', 0, $previous);
    }
}
