<?php

namespace App\Foundation\Auth\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The owner confirmed their email via the signed verification link
 * (UC-1-001 alt-6a). Identity-scoped (no company metadata) — verification is a
 * property of the user, not a tenant. verifiedAt is typed CarbonInterface
 * because spatie rehydrates a mutable Carbon on replay though the aggregate
 * records an immutable value.
 */
class UserEmailVerified extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public CarbonInterface $verifiedAt,
    ) {}
}
