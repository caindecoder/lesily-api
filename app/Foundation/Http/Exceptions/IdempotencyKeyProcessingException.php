<?php

namespace App\Foundation\Http\Exceptions;

use Exception;

/**
 * A concurrent request reused an Idempotency-Key whose first request is still in
 * flight (OQ-1-114). The winner holds the atomic reservation; this duplicate is
 * told to retry shortly, after which it replays the winner's remembered result.
 * Maps to error_code IDEMPOTENCY_KEY_PROCESSING (409) with a Retry-After header.
 */
class IdempotencyKeyProcessingException extends Exception
{
    public function __construct(public readonly int $retryAfter = 1)
    {
        parent::__construct('A request with this Idempotency-Key is already being processed. Please retry.');
    }
}
