<?php

namespace App\Companies\Memberships\Projectors;

use App\Companies\Entities\CompanyUser;
use App\Companies\Memberships\Events\UserInvited;
use App\Companies\Memberships\Entities\Invitation;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Foundation\Auth\Events\UserMembershipEmergencyContactSet;

/**
 * Membership-attribute projections from the User identity stream (slice C1). The
 * owner pivot itself is written by CompanyProjector::onCompanyCreated — the FK
 * chain (users → companies → company_user) means the pivot can only land once the
 * company row exists, so CompanyCreated (which carries ownerUserId) is the trigger;
 * by the time this EC event persists, the pivot row is in place.
 */
class MembershipProjector extends Projector
{
    /**
     * Upsert, not insert: a re-invite of a removed member reuses the SAME
     * invitation row (id = the resumed aggregate's UUID — D5b), so the fresh
     * token/role/expiry replace the old ones and accepted_at resets to pending.
     */
    public function onUserInvited(UserInvited $event): void
    {
        Invitation::query()->updateOrCreate(
            ['id' => $event->invitationId],
            [
                'company_id' => $event->companyId,
                'role_id' => $event->roleId,
                'email' => $event->email,
                'token_hash' => $event->tokenHash,
                'expires_at' => $event->expiresAt,
                'accepted_at' => null,
            ],
        );
    }

    public function onUserMembershipEmergencyContactSet(UserMembershipEmergencyContactSet $event): void
    {
        CompanyUser::query()
            ->where('company_id', $event->companyId)
            ->where('user_id', $event->userId)
            ->update([
                'emergency_contact_name' => $event->name,
                'emergency_contact_email' => $event->email,
                'emergency_contact_phone' => $event->phone,
                'emergency_contact_relationship' => $event->relationship,
            ]);
    }
}
