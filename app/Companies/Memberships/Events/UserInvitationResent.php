<?php

namespace App\Companies\Memberships\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-01 T7 (Invited → Invited): the bearer token was rotated and the 7-day
 * expiry reset — the previous link is dead from this event on (single live
 * token per membership, D3/R10). Carries the NEW sha256 only; companyId rides
 * the event metadata (S6 trim, s110 R2).
 */
class UserInvitationResent extends ShouldBeStored
{
    public function __construct(
        public string $membershipId,
        public string $tokenHash,
        public CarbonInterface $expiresAt,
        public string $resentBy,
    ) {}
}
