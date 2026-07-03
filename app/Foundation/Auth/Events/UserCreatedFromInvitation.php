<?php

namespace App\Foundation\Auth\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The SECOND genesis on the User identity stream (H1 session 109): an invitee
 * with no account set their name + password on the accept link (UC-1-019).
 * Carries the already-hashed password (never plaintext). Company context lives
 * on the sibling UserJoinedCompany / this event's metadata — the users row is
 * platform-global.
 */
class UserCreatedFromInvitation extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $name,
        public string $passwordHash,
    ) {}
}
