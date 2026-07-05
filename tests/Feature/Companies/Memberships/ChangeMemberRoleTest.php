<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Memberships\Events\UserMembershipRoleChanged;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ChangeMemberRoleTest extends MembershipsTestCase
{
    protected string $roleCode = 'hr_officer';

    #[Test]
    public function when_membership_is_unknown_then_change_is_refused_as_not_found(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();

        // When
        $response = $this->changeRole($admin, $company, (string) Str::uuid7());

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
        $this->assertNoRoleChangeRecorded();
    }

    #[Test]
    public function when_target_membership_is_the_owners_then_change_is_refused_as_owner_transfer_required(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        [, $ownerMembershipId] = $this->aMembership($company, 'owner');

        // When
        $response = $this->changeRole($admin, $company, $ownerMembershipId);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::OwnerTransferRequired);
        $this->assertMembershipRoleUnchanged($ownerMembershipId, $this->seededRole('owner'));
    }

    /**
     * Change the role of membership $membershipId as $actor acting in $company's
     * context — the new role rides the shared $this->roleCode field (the
     * RevokeInvitationTest $reason pattern).
     */
    protected function changeRole(User $actor, Company $company, string $membershipId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->patchJson(
            "/api/v1/memberships/{$membershipId}",
            ['role_code' => $this->roleCode],
            ['X-Company-Context' => $company->getKey()]
        );
    }

    /**
     * No UserMembershipRoleChanged event was ever recorded — the sad path was
     * rejected before any state change (for sads with no target row to inspect).
     */
    protected function assertNoRoleChangeRecorded(): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', UserMembershipRoleChanged::class)
            ->count());
    }

    /**
     * The membership still holds $expected and no UserMembershipRoleChanged event
     * was ever recorded for it — sad paths change nothing (the LD state-unchanged
     * discipline). withTrashed so removed-membership sads can assert too.
     */
    protected function assertMembershipRoleUnchanged(string $membershipId, Role $expected): void
    {
        $membership = CompanyUser::withTrashed()->whereKey($membershipId)->sole();

        $this->assertSame($expected->getKey(), $membership->role_id);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', UserMembershipRoleChanged::class)
            ->where('event_properties->membershipId', $membershipId)
            ->count());
    }
}
