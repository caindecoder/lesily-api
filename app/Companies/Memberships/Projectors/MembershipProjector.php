<?php

namespace App\Companies\Memberships\Projectors;

use App\Companies\Entities\CompanyUser;
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
