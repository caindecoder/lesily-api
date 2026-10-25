<?php

namespace App\Foundation\Auth\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Owner-signup genesis on the User identity stream (slice C1 — the sole signup
 * stream; the owner company_user pivot is projected from CompanyCreated, never from
 * a separate membership genesis). Carries the password HASH, never the plaintext
 * (UC-1-001 minimal guarantee). `companyName` is denormalised onto the event so the
 * after-commit welcome reactor needs no companies projection — that row is not yet
 * written when this fires (the owner persists first, per the owner_id FK chain).
 * OQ-1-108 tracks the upcaster a pre-launch event store needs for this added field.
 */
class UserSignedUpAsOwner extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public string $companyId,
        public string $companyName,
        public string $email,
        public string $name,
        public string $passwordHash,
    ) {}
}
