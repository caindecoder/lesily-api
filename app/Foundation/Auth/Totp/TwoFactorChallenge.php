<?php

namespace App\Foundation\Auth\Totp;

/**
 * The resolved state of a live 2FA login challenge: which user is mid-authentication
 * and how many verify attempts remain before the challenge self-destructs. A readonly
 * value object — the store reconstructs it on every `find`.
 */
class TwoFactorChallenge
{
    public function __construct(
        public readonly string $userId,
        public readonly int $attemptsRemaining,
    ) {}
}
