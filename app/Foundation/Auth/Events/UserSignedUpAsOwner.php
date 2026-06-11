<?php

namespace App\Foundation\Auth\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Owner-signup genesis on the User identity stream (slice C1 — the sole signup
 * stream; the owner company_user pivot is projected from this event, never from a
 * separate membership genesis). Carries the password HASH, never the plaintext
 * (UC-1-001 minimal guarantee).
 */
class UserSignedUpAsOwner extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public string $companyId,
        public string $email,
        public string $name,
        public string $passwordHash,
    ) {}
}
