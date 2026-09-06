<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Response;
use Laravel\Sanctum\NewAccessToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Events\UserRemovedFromCompany;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::RoleInsufficient);
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
        $this->assertRefusedWith($response, Response::HTTP_NOT_FOUND, ApiErrorCode::ResourceNotFound);
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
        $this->assertRefusedWith($response, Response::HTTP_NOT_FOUND, ApiErrorCode::ResourceNotFound);
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
        $this->assertRefusedWith($response, Response::HTTP_NOT_FOUND, ApiErrorCode::ResourceNotFound);
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
        $this->assertRefusedWith($response, Response::HTTP_NOT_FOUND, ApiErrorCode::ResourceNotFound);
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
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::SelfRemovalBlocked);
        $response->assertJsonPath('message', 'You cannot remove yourself. Ask another Owner/Admin.');
        $this->assertMemberStillActive($ownMembershipId);
    }

    #[Test]
    public function when_target_is_the_company_owner_then_removal_requires_ownership_transfer(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        [, $ownerMembershipId] = $this->aMembership($company, 'owner');

        // When the admin targets the owner's membership
        $response = $this->removeMember($admin, $company, $ownerMembershipId);

        // Then the Owner is un-removable — ownership transfer required (R-A parity)
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::OwnerTransferRequired);
        $response->assertJsonPath('message', 'The company owner cannot be removed. Transfer ownership first.');
        $this->assertMemberStillActive($ownerMembershipId);
    }

    #[Test]
    public function when_owner_removes_their_own_membership_then_self_block_takes_precedence(): void
    {
        // Given the company Owner — who satisfies BOTH 422 guards at once
        [$company, $owner] = $this->aCompanyWithMember('owner');
        $ownMembershipId = $this->membershipIdOf($company, $owner);

        // When
        $response = $this->removeMember($owner, $company, $ownMembershipId);

        // Then the self-guard wins over the owner-target guard — locks the
        // interactor's guard ORDER as contract (self before owner; s112 parity)
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::SelfRemovalBlocked);
        $this->assertMemberStillActive($ownMembershipId);
    }

    #[Test]
    public function when_a_member_with_active_sessions_is_removed_then_their_sessions_are_revoked(): void
    {
        // Given a member with two active sessions
        [$company, $admin] = $this->aCompanyWithMember();
        [$target, $membershipId] = $this->anAcceptedMembership($company, $admin, 'bob@contoso.lk');
        $session = $this->aSessionFor($target, 'web');
        $this->aSessionFor($target, 'mobile');

        // When the member is removed
        $this->removeMember($admin, $company, $membershipId)->assertNoContent();

        // Then every one of their sessions is revoked — none authenticates
        $this->assertMemberSessionsRevoked($target, $session);
    }

    #[Test]
    public function when_a_removed_member_is_re_invited_then_the_same_membership_row_is_restored(): void
    {
        // Given a member removed through the endpoint (event-sourced soft-delete)
        [$company, $admin] = $this->aCompanyWithMember();
        [$target, $membershipId] = $this->anAcceptedMembership($company, $admin, 'bob@contoso.lk');
        $this->removeMember($admin, $company, $membershipId)->assertNoContent();

        // When they are re-invited and accept while authenticated
        $token = $this->aPendingInvitation($company, $admin, 'bob@contoso.lk');
        $response = $this->acceptInvitation($token, [], $target);

        // Then the SAME pivot UUID comes back live (undelete-in-place, D5b) — the
        // real endpoint's removal leaves exactly the reusable soft-deleted state,
        // and no second row is minted
        $response->assertOk();
        $response->assertJsonPath('data.id', $membershipId);
        $this->assertMemberStillActive($membershipId);
        $this->assertSame(1, CompanyUser::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('user_id', $target->getKey())
            ->count());
    }

    #[Test]
    public function when_a_member_is_removed_then_their_other_company_membership_remains(): void
    {
        // Given Bob is a member of company A AND company B
        [$companyA, $adminA] = $this->aCompanyWithMember();
        [$bob, $membershipIdA] = $this->anAcceptedMembership($companyA, $adminA, 'bob@contoso.lk');
        [$companyB] = $this->aCompanyWithMember();
        $this->attachMember($companyB, $bob, $this->seededRole('employee'));
        $membershipIdB = $this->membershipIdOf($companyB, $bob);

        // When Bob is removed from company A
        $this->removeMember($adminA, $companyA, $membershipIdA)->assertNoContent();

        // Then only company A's pivot is soft-deleted — company B is untouched
        $this->assertMemberStillActive($membershipIdB);
    }

    #[Test]
    #[DataProvider('provideAuthorizedActors')]
    public function when_an_authorized_actor_removes_a_member_then_the_member_is_removed(string $actorRole): void
    {
        // Given a real accepted member (a genuine event stream — amendment R)
        [$company, $actor] = $this->aCompanyWithMember($actorRole);
        [$target, $membershipId] = $this->anAcceptedMembership($company, $actor, 'bob@contoso.lk');

        // When an authorized actor removes them
        $response = $this->removeMember($actor, $company, $membershipId);

        // Then the pivot is soft-deleted, the event is recorded, and they drop
        // off the roster
        $this->assertMemberRemoved($response, $actor, $membershipId);
        $this->assertRosterExcludes($this->viewMemberships($actor, $company), $target);
    }

    /**
     * @param  array{sqlstate: string, code: int, message: string}  $failure
     */
    #[Test]
    #[DataProvider('contentionProvider')]
    public function when_the_removal_hits_lock_contention_then_it_is_refused_as_a_conflict(
        array $failure,
        int $expectedStatus,
        ApiErrorCode $expected,
    ): void {
        // Given — a member to remove and a write inside the locked
        // transaction that dies the way the driver reports it
        [$company, $admin] = $this->aCompanyWithMember();
        [, $membershipId] = $this->anAcceptedMembership($company, $admin, 'bob@contoso.lk');
        $this->induceProjectorFailureFor(UserRemovedFromCompany::class, $this->syntheticQueryException($failure));

        // When — the admin removes the member
        $response = $this->removeMember($admin, $company, $membershipId);

        // Then — contention answers as a conflict; anything else passes through
        $this->assertRefusedWith($response, $expectedStatus, $expected);
    }

    #[Test]
    public function when_the_removal_reads_the_membership_it_will_write_then_it_locks_it(): void
    {
        // Given
        [$company, $admin] = $this->aCompanyWithMember();
        [, $membershipId] = $this->anAcceptedMembership($company, $admin, 'bob@contoso.lk');

        // When
        $capture = $this->recordedQueriesDuring(
            fn () => $this->removeMember($admin, $company, $membershipId)->assertNoContent()
        );

        // Then
        $this->assertOwnerGuardDecidedUnderLock($capture, 'company_user');
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

    /**
     * Roles WITH users.remove (matrix D4/D10) — owner and admin only.
     *
     * @return array<string, array{0: string}>
     */
    public static function provideAuthorizedActors(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
        ];
    }

    /**
     * The member was removed the event-sourced way: 204, the company_user pivot
     * soft-deleted (deleted_at set), and exactly one UserRemovedFromCompany
     * stored event carries this membership + actor verbatim with the company_id
     * metadata (amendment R — a direct read-model write must fail this).
     */
    protected function assertMemberRemoved(TestResponse $response, User $actor, string $membershipId): void
    {
        $response->assertNoContent();

        $membership = CompanyUser::withTrashed()->whereKey($membershipId)->sole();
        $this->assertNotNull($membership->deleted_at);

        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserRemovedFromCompany::class)
            ->where('event_properties->membershipId', $membershipId)
            ->where('event_properties->removedBy', $actor->getKey())
            ->whereNotNull('event_properties->removedAt')
            ->where('company_id', $membership->company_id)
            ->count());
    }

    /**
     * The roster no longer lists $absent — the removed member dropped off the
     * active-members read model.
     */
    protected function assertRosterExcludes(TestResponse $roster, User $absent): void
    {
        $roster->assertOk();
        $this->assertNotContains($absent->getKey(), $roster->collect('data')->pluck('user.id')->all());
    }

    /**
     * The removed member holds no session and a previously-valid token no longer
     * authenticates (D6 inline coarse account-level kill; forgetGuards forces a
     * fresh DB re-check — amendment P).
     */
    protected function assertMemberSessionsRevoked(User $target, NewAccessToken $session): void
    {
        $this->assertSame(0, $target->tokens()->count());

        Auth::forgetGuards();
        $this->withHeader('Authorization', "Bearer {$session->plainTextToken}")
            ->getJson('/api/_test/authenticated-probe')
            ->assertUnauthorized();
    }
}
