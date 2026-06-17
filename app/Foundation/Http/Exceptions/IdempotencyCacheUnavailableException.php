<?php

namespace App\Foundation\Http\Exceptions;

use Exception;

/**
 * The idempotency store (Redis) was unreachable on a required idempotency route.
 * UC-1-119 fails CLOSED — rejecting is safer than risking a duplicate
 * side-effect. Maps to error_code IDEMPOTENCY_CACHE_UNAVAILABLE (503).
 */
class IdempotencyCacheUnavailableException extends Exception
{
    public function __construct()
    {
        parent::__construct('The idempotency store is currently unavailable. Please retry.');
    }
}
