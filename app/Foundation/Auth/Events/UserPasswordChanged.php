<?php

namespace App\Foundation\Auth\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The account password was changed by its owner after a current-password re-auth (UC-1-016).
 * Unlike the 2FA markers, this carries the already-hashed new password (the register precedent —
 * the hash, never plaintext, reaches the store): users.password is a PROJECTED column, so the
 * write MUST be event-sourced to survive a projection replay (a direct save would be reverted).
 * The UserProjector applies passwordHash to users.password; changedAt is non-secret audit metadata.
 */
class UserPasswordChanged extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public string $passwordHash,
        public CarbonInterface $changedAt,
    ) {}
}
