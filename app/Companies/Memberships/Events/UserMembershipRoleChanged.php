<?php

namespace App\Companies\Memberships\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A membership's role was reassigned (UC-1-023 — non-lifecycle attribute
 * mutation, no SM transition). Payload trimmed per R-C s112: companyId rides
 * the stream metadata (genesis replay re-tags it) and userId is derivable via
 * the membershipId join; from/to stay explicit for the audit postcondition and
 * for the UC-1-093 ownership-transfer saga, which records this same event
 * twice in one transaction (old Owner → Admin, new Owner → Owner).
 */
class UserMembershipRoleChanged extends ShouldBeStored
{
    public function __construct(
        public string $membershipId,
        public string $fromRoleId,
        public string $toRoleId,
        public string $changedBy,
        public CarbonInterface $changedAt,
    ) {}
}
