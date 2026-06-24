<?php

namespace App\Foundation\Auth\UseCases\Results;

use DateTimeInterface;

/**
 * LogInInteractor outcome for a 2FA-enrolled user: a transient challenge was opened and NO
 * token issued — login is suspended at "awaiting-2fa" until /2fa/verify completes it. The
 * controller renders this as a 202 (TwoFactorChallengeResource).
 */
class ChallengeRequired extends LoginOutcome
{
    public function __construct(
        public readonly string $challengeId,
        public readonly DateTimeInterface $expiresAt,
    ) {}
}
