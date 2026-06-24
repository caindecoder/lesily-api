<?php

namespace App\Foundation\Auth\UseCases\Results;

use DateTimeInterface;

/**
 * The token-mint response model (E07) crossing back from SessionIssuer to the flow
 * interactors and the delivery ring: the plain-text Sanctum token plus its expiry. No
 * Sanctum NewAccessToken type leaks past it.
 */
class IssuedSession
{
    public function __construct(
        public readonly string $token,
        public readonly ?DateTimeInterface $expiresAt,
    ) {}
}
