<?php

namespace App\Foundation\Auth\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A single-use recovery code was consumed to complete a 2FA login (UC-1-005). A
 * non-secret state/audit marker (Design-Y): the recovery-code hashes live in the users
 * row and are mutated directly by the verify interactor; this event carries only the
 * count of codes still usable, for the security/audit log. Identity-scoped (no company
 * metadata) — 2FA is a property of the person, not a tenant.
 */
class User2FARecoveryCodeUsed extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public int $remaining,
    ) {}
}
