<?php

namespace App\Companies\Memberships\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Genesis of the UserMembership stream (SM-1-01 T1: [*] → Invited). Carries the
 * sha256 tokenHash, never the plaintext — the accept-link secret leaves the
 * interactor only inside the InvitationMail it dispatches inline (D3/R10).
 */
class UserInvited extends ShouldBeStored
{
    public function __construct(
        public string $invitationId,
        public string $companyId,
        public string $email,
        public string $roleId,
        public string $invitedBy,
        public CarbonInterface $expiresAt,
        public string $tokenHash,
    ) {}
}
