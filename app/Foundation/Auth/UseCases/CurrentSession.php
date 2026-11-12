<?php

namespace App\Foundation\Auth\UseCases;

use App\Foundation\AuditTrail\Values\RequestOrigin;

/**
 * The request an account change arrives on: the session to keep and where it came from.
 */
class CurrentSession
{
    public function __construct(
        public readonly string $tokenId,
        public readonly RequestOrigin $origin,
    ) {}
}
