<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Entities\Invitation;

class RemoveMemberTest extends MembershipsTestCase
{
    #[Test]
    #[DataProvider('provideDeniedRemoveRoles')]
    public function when_actor_lacks_remove_permission_then_removal_is_denied(string $roleCode): void
    {
        // Given a member to remove and an actor whose role lacks users.remove
        [$company] = $this->aCompanyWithMember();
        [, $membershipId] = $this->aMembership($company, 'employee');
        $actor = User::factory()->create();
        $this->attachMember($company, $actor, $this->seededRole($roleCode));

        // When the actor attempts to remove the member
        $response = $this->removeMember($actor, $company, $membershipId);

        // Then the request is refused and the member is untouched
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
        $this->assertMemberStillActive($membershipId);
    }

    #[Test]
    public function when_membership_id_is_unknown_then_removal_is_not_found(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();

        // When
        $response = $this->removeMember($admin, $company, (string) Str::uuid7());

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    #[Test]
    public function when_membership_belongs_to_another_company_then_removal_is_not_found(): void
    {
        // Given a member of company B and an admin acting in company A
        [$companyA, $adminA] = $this->aCompanyWithMember();
        [$companyB] = $this->aCompanyWithMember();
        [, $membershipIdB] = $this->aMembership($companyB, 'employee');

        // When the company-A admin targets company B's membership id
        $response = $this->removeMember($adminA, $companyA, $membershipIdB);

        // Then the company-scoped lookup hides it as not found
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
        $this->assertMemberStillActive($membershipIdB);
    }

    #[Test]
    public function when_membership_is_already_removed_then_removal_is_not_found(): void
    {
        // Given a membership that was already removed (soft-deleted pivot)
        [$company, $admin] = $this->aCompanyWithMember();
        $removedMembershipId = $this->aRemovedMembership($company, User::factory()->create());

        // When it is removed again
        $response = $this->removeMember($admin, $company, $removedMembershipId);

        // Then the SoftDeletes scope hides it — a member can't be removed twice
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    #[Test]
    public function when_target_is_still_a_pending_invitation_then_removal_is_not_found(): void
    {
        // Given an invitation whose UUID becomes the membership id on accept (D5b),
        // but no company_user pivot exists yet
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitationId = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', 'invitee@contoso.lk')
            ->sole()
            ->getKey();

        // When the pending invitation id is targeted
        $response = $this->removeMember($admin, $company, $invitationId);

        // Then there is no pivot to remove — not found
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    #[Test]
    public function when_actor_removes_their_own_membership_then_removal_is_blocked(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $ownMembershipId = $this->membershipIdOf($company, $admin);

        // When the actor targets their own membership
        $response = $this->removeMember($admin, $company, $ownMembershipId);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::SelfRemovalBlocked);
        $response->assertJsonPath('message', 'You cannot remove yourself. Ask another Owner/Admin.');
        $this->assertMemberStillActive($ownMembershipId);
    }

    /**
     * Roles WITHOUT users.remove — only Owner/Admin hold it (matrix D4/D10;
     * hr_officer may invite/revoke-invitation but NOT remove members).
     *
     * @return array<string, array{0: string}>
     */
    public static function provideDeniedRemoveRoles(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }
}
