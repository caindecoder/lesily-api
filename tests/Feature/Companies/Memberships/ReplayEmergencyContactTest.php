<?php

namespace Tests\Feature\Companies\Memberships;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use Spatie\EventSourcing\Projectionist;
use App\Companies\Memberships\Projectors\MembershipProjector;

/**
 * UC-1-029 — a recorded emergency contact replays onto its membership even after
 * the member was removed (the projector's other handlers keep removed rows the
 * same way). Proven at the projector: no request can reach a removed membership,
 * so only a replay meets one.
 */
class ReplayEmergencyContactTest extends MembershipsTestCase
{
    #[Test]
    public function when_a_removed_members_contact_is_replayed_then_it_lands_on_their_row(): void
    {
        // Given an owner who set a contact through the endpoint and was later removed
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        $this->updateEmergencyContact($owner, $company->getKey(), self::UPDATED_CONTACT);
        $membership = CompanyUser::query()->where('company_id', $company->getKey())->where('user_id', $owner->getKey())->sole();
        $membership->forceFill(['emergency_contact_name' => null, 'emergency_contact_email' => null, 'emergency_contact_phone' => null, 'emergency_contact_relationship' => null])->save();
        $membership->delete();

        // When the membership projector replays the stored events
        app(Projectionist::class)->replay(collect([app(MembershipProjector::class)]));

        // Then the contact lands on the removed member's row
        $this->assertSame(self::UPDATED_CONTACT, $this->storedContactOfRemoved($company->getKey(), $owner->getKey()));
    }

    /**
     * @return array<string, string|null>
     */
    protected function storedContactOfRemoved(string $companyId, string $userId): array
    {
        $membership = CompanyUser::withTrashed()->where('company_id', $companyId)->where('user_id', $userId)->sole();

        return [
            'name' => $membership->emergency_contact_name,
            'email' => $membership->emergency_contact_email,
            'phone' => $membership->emergency_contact_phone,
            'relationship' => $membership->emergency_contact_relationship,
        ];
    }
}
