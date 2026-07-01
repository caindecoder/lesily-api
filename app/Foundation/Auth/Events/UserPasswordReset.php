<?php

namespace App\Foundation\Auth\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The account password was reset by its owner via an emailed single-use link (UC-1-008). Like
 * UserPasswordChanged it carries the already-hashed new password (the hash, never plaintext, reaches
 * the store): users.password is a PROJECTED column, so the write MUST be event-sourced to survive a
 * projection replay (a direct save would be reverted). The UserProjector applies passwordHash to
 * users.password; resetAt is non-secret audit metadata. Session revocation is a separate side-effect.
 */
class UserPasswordReset extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public string $passwordHash,
        public CarbonInterface $resetAt,
    ) {}
}
