<?php

namespace App\Companies\Exceptions;

use Exception;

/**
 * Under the row lock the company's owner is no longer the actor — a concurrent
 * transfer committed first (UC-1-028 flow 3b; STATE_GUARD_VIOLATION 409).
 */
class StaleOwnershipException extends Exception
{
    public function __construct()
    {
        parent::__construct('Company ownership changed while this request was in flight.');
    }
}
