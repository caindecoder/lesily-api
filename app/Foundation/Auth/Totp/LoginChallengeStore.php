<?php

namespace App\Foundation\Auth\Totp;

/**
 * Bridges the two HTTP requests of a 2FA login: login issues a short-lived challenge
 * (request 1), /2fa/verify consumes it (request 2). A DIP boundary (E13) — the login
 * flow depends on this interface, not on the cache/Redis detail behind it; #03's verify
 * interactor stubs it (E23). The opaque challenge_id is what the client holds, never the
 * user id.
 */
interface LoginChallengeStore
{
    public const int TTL_MINUTES = 5;

    public const int MAX_ATTEMPTS = 5;

    /** Open a challenge for the authenticated user; returns the opaque challenge id. */
    public function create(string $userId): string;

    /** Resolve a live challenge, or null if it expired / was never created / is spent. */
    public function find(string $challengeId): ?TwoFactorChallenge;

    /** Count one failed verify attempt; deletes the challenge once the allowance is spent. */
    public function recordFailure(string $challengeId): void;

    /** Discard the challenge (on a successful verify). */
    public function forget(string $challengeId): void;
}
