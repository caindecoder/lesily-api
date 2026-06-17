<?php

namespace App\Foundation\Http\Exceptions;

use Exception;

/**
 * A route declaring `idempotency-key:required` was called without an
 * `Idempotency-Key` header. Maps to error_code IDEMPOTENCY_KEY_REQUIRED (422)
 * per api-1-infrastructure §S21 / UC-1-119. Rendered in bootstrap/app.php,
 * symmetric with the other Foundation\Http exceptions.
 */
class IdempotencyKeyRequiredException extends Exception
{
    public function __construct()
    {
        parent::__construct('An Idempotency-Key header is required for this request.');
    }
}
