<?php

namespace App\Companies\Memberships\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-01 T6 (Invited → InvitationRevoked, terminal): an Owner/Admin/HR Officer
 * cancelled the pending invitation — the bearer token is dead from this event on
 * (D3/R10). Payload trimmed per R-C s111: companyId rides the event metadata;
 * the invitee email is derivable via the membershipId join.
 */
class UserInvitationRevoked extends ShouldBeStored
{
    public function __construct(
        public string $membershipId,
        public string $revokedBy,
        public CarbonInterface $revokedAt,
        public ?string $reason,
    ) {}
}
