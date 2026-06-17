<?php

namespace App\Foundation\Http\Exceptions;

use Exception;

/**
 * An Idempotency-Key was re-used with a different request body within the 24h
 * dedup window (UC-1-119). The original mutation is left untouched. Maps to
 * error_code IDEMPOTENCY_KEY_CONFLICT (409).
 */
class IdempotencyKeyConflictException extends Exception
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used for a different request.');
    }
}
