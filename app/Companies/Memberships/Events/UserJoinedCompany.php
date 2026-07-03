<?php

namespace App\Companies\Memberships\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-01 T4 (Invited → Active): the invitee consumed their token. The projector
 * stamps invitations.accepted_at and writes the company_user pivot on the SAME
 * UUID (create, or undelete-in-place for a Removed member — D5b). companyId
 * rides the event METADATA (forCompany), not the payload; a new-email joiner's
 * user creation is the separate UserCreatedFromInvitation genesis on the Auth
 * User aggregate (H1 session 109).
 */
class UserJoinedCompany extends ShouldBeStored
{
    public function __construct(
        public string $membershipId,
        public string $userId,
        public string $roleId,
        public CarbonInterface $joinedAt,
    ) {}
}
