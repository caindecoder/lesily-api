<?php

namespace App\Companies\Memberships\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-01 T5 (Invited → InvitationExpired, terminal): the 7-day window elapsed
 * without acceptance — the daily `invitations:expire` scan (System actor, no UC)
 * records this and the bearer token is dead from here on. Payload trimmed per the
 * s113 house convention: companyId rides the event metadata and the invitee email
 * is derivable via the membershipId join. expiredAt is the run cutoff — one
 * consistent instant across a batch — stamped onto invitations.expired_at.
 */
class UserInvitationExpired extends ShouldBeStored
{
    public function __construct(
        public string $membershipId,
        public CarbonInterface $expiredAt,
    ) {}
}
