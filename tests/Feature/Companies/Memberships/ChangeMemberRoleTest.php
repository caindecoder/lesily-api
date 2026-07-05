<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Memberships\Events\UserMembershipRoleChanged;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ChangeMemberRoleTest extends MembershipsTestCase
{
    protected ?string $roleCode = 'hr_officer';

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
    #[DataProvider('provideDeniedChangeRoleRoles')]
    public function when_actor_role_lacks_change_permission_then_change_is_refused(string $roleCode): void
    {
        // Given
        [$company] = $this->aCompanyWithMember();
        [, $membershipId] = $this->aMembership($company, 'employee');
        $actor = User::factory()->create();
        $this->attachMember($company, $actor, $this->seededRole($roleCode));

        // When
        $response = $this->changeRole($actor, $company, $membershipId);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
        $this->assertMembershipRoleUnchanged($membershipId, $this->seededRole('employee'));
    }

    /**
     * Roles WITHOUT users.change-role — only Owner/Admin hold it (matrix D4;
     * hr_officer may invite/revoke-invitation but NOT change roles).
     *
     * @return array<string, array{0: string}>
     */
    public static function provideDeniedChangeRoleRoles(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }

    #[Test]
    public function when_membership_belongs_to_another_company_then_change_is_refused_as_not_found(): void
    {
        // Given
        [$companyA, $adminA] = $this->aCompanyWithMember();
        [$companyB] = $this->aCompanyWithMember();
        [, $membershipIdB] = $this->aMembership($companyB, 'employee');

        // When
        $response = $this->changeRole($adminA, $companyA, $membershipIdB);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
        $this->assertMembershipRoleUnchanged($membershipIdB, $this->seededRole('employee'));
    }

    #[Test]
    #[DataProvider('provideNonAssignableRoleCodes')]
    public function when_role_code_is_not_assignable_then_change_is_rejected(?string $roleCode): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        [, $membershipId] = $this->aMembership($company, 'employee');
        $this->roleCode = $roleCode;

        // When
        $response = $this->changeRole($admin, $company, $membershipId);

        // Then
        $this->assertValidationRejected($response, 'roleCode');
        $this->assertMembershipRoleUnchanged($membershipId, $this->seededRole('employee'));
    }

    /**
     * Role codes the whitelist must reject — owner is never change-role-assignable
     * (ownership transfer only, R-A s112), platform_super_admin is not a company
     * role, manager is seeded is_assignable=false, unknown codes don't exist, and
     * a missing field fails required. The chunk-1 Enterprise custom-role gate is a
     * declared deferral (R-F s112) — this whitelist IS today's buildable edge.
     *
     * @return array<string, array{0: ?string}>
     */
    public static function provideNonAssignableRoleCodes(): array
    {
        return [
            'owner' => ['owner'],
            'platform super admin' => ['platform_super_admin'],
            'manager' => ['manager'],
            'unknown code' => ['ninja'],
            'missing' => [null],
        ];
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
        $response->assertJsonPath('message', "The Owner's role can only be changed through an ownership transfer.");
        $this->assertMembershipRoleUnchanged($ownerMembershipId, $this->seededRole('owner'));
    }

    #[Test]
    public function when_actor_targets_their_own_membership_then_change_is_refused(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $ownMembershipId = $this->membershipIdOf($company, $admin);

        // When
        $response = $this->changeRole($admin, $company, $ownMembershipId);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::SelfRoleChangeBlocked);
        $response->assertJsonPath('message', 'You cannot change your own role. Ask another Owner/Admin.');
        $this->assertMembershipRoleUnchanged($ownMembershipId, $this->seededRole('admin'));
    }

    /**
     * Change the role of membership $membershipId as $actor acting in $company's
     * context — the new role rides the shared $this->roleCode field (the
     * RevokeInvitationTest $reason pattern; camelCase body key = invite parity).
     * A null roleCode omits the field entirely (the missing-body-field sad).
     */
    protected function changeRole(User $actor, Company $company, string $membershipId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->patchJson(
            "/api/v1/memberships/{$membershipId}",
            $this->roleCode === null ? [] : ['roleCode' => $this->roleCode],
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
