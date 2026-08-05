<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Entities\Invitation;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Memberships\Events\UserMembershipRoleChanged;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ChangeMemberRoleTest extends MembershipsTestCase
{
    protected ?string $roleCode = 'hr_officer';

    protected string $fromRoleCode = 'employee';

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

    #[Test]
    public function when_owner_targets_their_own_membership_then_self_block_wins(): void
    {
        // Given the company Owner — who satisfies BOTH 422 guards at once
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $ownMembershipId = $this->membershipIdOf($company, $owner);

        // When
        $response = $this->changeRole($owner, $company, $ownMembershipId);

        // Then the self-guard takes precedence over the owner-target guard —
        // locks the interactor's guard ORDER as contract (two distinct codes;
        // pr-test-analyzer IMPORTANT-1, s112)
        $this->assertRefusedWith($response, ApiErrorCode::SelfRoleChangeBlocked);
        $this->assertMembershipRoleUnchanged($ownMembershipId, $this->seededRole('owner'));
    }

    #[Test]
    public function when_target_membership_is_removed_then_change_is_refused_as_not_found(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        $removedMembershipId = $this->aRemovedMembership($company, User::factory()->create());

        // When
        $response = $this->changeRole($admin, $company, $removedMembershipId);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
        $this->assertMembershipRoleUnchanged($removedMembershipId, $this->seededRole('employee'));
    }

    #[Test]
    public function when_target_is_still_a_pending_invitation_then_change_is_refused_as_not_found(): void
    {
        // Given an invitation whose UUID will become the membership id on accept
        // (D5b shared identity) — but no company_user row exists yet
        [$company, $admin] = $this->aCompanyWithMember();
        $this->aPendingInvitation($company, $admin, 'invitee@contoso.lk');
        $invitationId = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', 'invitee@contoso.lk')
            ->sole()
            ->getKey();

        // When
        $response = $this->changeRole($admin, $company, $invitationId);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
        $this->assertNoRoleChangeRecorded();
    }

    #[Test]
    public function when_new_role_equals_current_role_then_change_succeeds(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        [, $membershipId] = $this->anAcceptedMembership($company, $admin, 'bob@contoso.lk');
        $this->roleCode = 'employee';

        // When
        $response = $this->changeRole($admin, $company, $membershipId);

        // Then a same-role change is a legitimate no-op success — the aggregate
        // is guardless (cd-users §5), so the event records from == to
        $this->assertRoleChangeEnvelope($response, $this->seededRole('employee'), $membershipId);
        $this->assertMembershipRoleChanged($membershipId, $admin, $this->seededRole('employee'));
    }

    #[Test]
    #[DataProvider('provideAllowedChangeRoleRoles')]
    public function when_authorized_actor_changes_member_role_then_role_is_updated(string $actorRole): void
    {
        // Given
        [$company, $actor] = $this->aCompanyWithMember($actorRole);
        [, $membershipId] = $this->anAcceptedMembership($company, $actor, 'bob@contoso.lk');

        // When
        $response = $this->changeRole($actor, $company, $membershipId);

        // Then
        $this->assertRoleChangeEnvelope($response, $this->seededRole('hr_officer'), $membershipId);
        $this->assertMembershipRoleChanged($membershipId, $actor, $this->seededRole('hr_officer'));
    }

    #[Test]
    public function when_admin_changes_another_admins_role_then_change_succeeds(): void
    {
        // Given a second admin, promoted through the endpoint (arrange via the
        // real flow — aRevokedInvitation precedent)
        [$company, $admin] = $this->aCompanyWithMember();
        [, $membershipId] = $this->anAcceptedMembership($company, $admin, 'bob@contoso.lk');
        $this->roleCode = 'admin';
        $this->changeRole($admin, $company, $membershipId)->assertOk();

        // When the admin demotes the other admin (UC alt-4b — allowed; R-G s112:
        // no sole-admin guard, the immutable Owner is the escalation root)
        $this->roleCode = 'employee';
        $this->fromRoleCode = 'admin';
        $response = $this->changeRole($admin, $company, $membershipId);

        // Then
        $this->assertRoleChangeEnvelope($response, $this->seededRole('employee'), $membershipId);
        $this->assertMembershipRoleChanged($membershipId, $admin, $this->seededRole('employee'));
    }

    #[Test]
    public function when_role_changes_then_target_permissions_reflect_immediately_on_next_request(): void
    {
        // Given an employee who cannot view the roster (employee lacks users.view)
        [$company, $admin] = $this->aCompanyWithMember();
        [$bob, $membershipId] = $this->anAcceptedMembership($company, $admin, 'bob@contoso.lk');
        $this->assertRefusedWith($this->viewMemberships($bob, $company), ApiErrorCode::RoleInsufficient);

        // When their role changes to HR Officer (which holds users.view)
        $this->changeRole($admin, $company, $membershipId)->assertOk();
        Auth::forgetGuards();

        // Then the very next request already runs under the new role — no stale
        // permission state (UC postcondition; no permission cache, OQ-1-035)
        $this->viewMemberships($bob, $company)->assertOk();
    }

    #[Test]
    public function when_target_belongs_to_two_companies_then_only_current_company_role_changes(): void
    {
        // Given Bob is an employee in company A AND an admin in company B
        [$companyA, $adminA] = $this->aCompanyWithMember();
        [$bob, $membershipIdA] = $this->anAcceptedMembership($companyA, $adminA, 'bob@contoso.lk');
        [$companyB] = $this->aCompanyWithMember();
        $this->attachMember($companyB, $bob, $this->seededRole('admin'));
        $membershipIdB = $this->membershipIdOf($companyB, $bob);

        // When Bob's role changes in company A's context
        $response = $this->changeRole($adminA, $companyA, $membershipIdA);

        // Then company A holds the new role and company B is untouched
        $this->assertRoleChangeEnvelope($response, $this->seededRole('hr_officer'), $membershipIdA);
        $this->assertMembershipRoleChanged($membershipIdA, $adminA, $this->seededRole('hr_officer'));
        $this->assertMembershipRoleUnchanged($membershipIdB, $this->seededRole('admin'));
    }

    #[Test]
    public function when_the_role_change_reads_the_membership_it_will_write_then_it_locks_it(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        [, $membershipId] = $this->anAcceptedMembership($company, $admin, 'bob@contoso.lk');

        // When
        $capture = $this->recordedQueriesDuring(
            fn () => $this->changeRole($admin, $company, $membershipId)->assertOk()
        );

        // Then
        $this->assertOwnerGuardDecidedUnderLock($capture, 'company_user');
    }

    #[Test]
    public function when_the_projector_fails_mid_role_change_then_nothing_is_persisted(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        [, $membershipId] = $this->anAcceptedMembership($company, $admin, 'bob@contoso.lk');
        $this->induceProjectorFailureFor(UserMembershipRoleChanged::class);

        // When
        $response = $this->changeRole($admin, $company, $membershipId);

        // Then — the transaction the lock row proves PRESENT rolls back whole:
        // zero stored events, role untouched (the T2-deferred atomicity half)
        $this->assertRefusedWith($response, ApiErrorCode::InternalError);
        $this->assertMembershipRoleUnchanged($membershipId, $this->seededRole('employee'));
    }

    /**
     * Roles WITH users.change-role (matrix D4) — owner and admin only.
     *
     * @return array<string, array{0: string}>
     */
    public static function provideAllowedChangeRoleRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
        ];
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
     * The 200 envelope carries the updated roster row: {data: MembershipResource}
     * for exactly $membershipId with the NEW role and the active status
     * (R-E s112 — no leaked pivot columns; the id pin guards findById's scope).
     */
    protected function assertRoleChangeEnvelope(TestResponse $response, Role $to, string $membershipId): void
    {
        $response->assertOk();
        $this->assertSame($membershipId, $response->json('data.id'));
        $this->assertSame([$to->code, $to->name], [$response->json('data.role.code'), $response->json('data.role.name')]);
        $this->assertSame('active', $response->json('data.status'));
    }

    /**
     * The role change landed the event-sourced way: company_user.role_id holds
     * $to AND exactly one UserMembershipRoleChanged stored event carries this
     * membership's from/to/changedBy verbatim with the company_id metadata
     * (amendment R — a direct read-model write must fail this). The from-role
     * rides the shared $this->fromRoleCode (§2.2 shared test data).
     */
    protected function assertMembershipRoleChanged(string $membershipId, User $changedBy, Role $to): void
    {
        $membership = CompanyUser::query()->whereKey($membershipId)->sole();

        $this->assertSame($to->getKey(), $membership->role_id);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserMembershipRoleChanged::class)
            ->where('event_properties->membershipId', $membershipId)
            ->where('event_properties->fromRoleId', $this->seededRole($this->fromRoleCode)->getKey())
            ->where('event_properties->toRoleId', $to->getKey())
            ->where('event_properties->changedBy', $changedBy->getKey())
            ->whereNotNull('event_properties->changedAt')
            ->where('company_id', $membership->company_id)
            ->count());
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
