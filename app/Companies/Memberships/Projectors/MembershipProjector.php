<?php

namespace App\Companies\Memberships\Projectors;

use App\Companies\Entities\CompanyUser;
use App\Companies\Memberships\Events\UserInvited;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Events\UserJoinedCompany;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Foundation\Auth\Events\UserMembershipEmergencyContactSet;

/**
 * The membership read-model writer, fed by TWO streams: the UserMembership
 * aggregate's invite lifecycle (UserInvited → the invitations table; the later
 * accept/revoke/expire/role-change/remove arms land with their slice tasks) and
 * the User identity stream's membership attributes (emergency contact → the
 * company_user pivot, slice C1). The owner pivot itself is written by
 * CompanyProjector::onCompanyCreated — the FK chain (users → companies →
 * company_user) means the pivot can only land once the company row exists;
 * by the time the EC event persists, the pivot row is in place.
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

    /**
     * SM-1-01 T4: stamp the invitation accepted and write the pivot on the SAME
     * UUID (D5b identity — invitation.id == company_user.id == invitation_id;
     * a plain attach() would mint a fresh UUID). company_id comes off the
     * invitation row this projector wrote at invite time (same transaction).
     */
    public function onUserJoinedCompany(UserJoinedCompany $event): void
    {
        $invitation = Invitation::query()->findOrFail($event->membershipId);
        $invitation->update(['accepted_at' => $event->joinedAt]);

        CompanyUser::query()->create([
            'id' => $event->membershipId,
            'company_id' => $invitation->company_id,
            'user_id' => $event->userId,
            'role_id' => $event->roleId,
            'invitation_id' => $event->membershipId,
        ]);
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
