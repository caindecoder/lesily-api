<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Events\UserInvited;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Mail\InvitationMail;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class InviteUserTest extends MembershipsTestCase
{
    // --- Sad ---

    #[Test]
    public function when_the_email_is_already_an_active_member_then_the_invite_is_rejected(): void
    {
        // Given an admin of a company and a target who is already a member
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $target = User::factory()->withEmail('member@contoso.lk')->create();
        $this->attachMember($company, $target, $this->seededRole('employee'));

        // When the admin invites that member's email again
        $response = $this->invite($admin, $company, [
            'email' => 'member@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then it is refused as an existing member and nothing is created
        $response->assertUnprocessable();
        $response->assertJsonPath('error_code', 'ALREADY_MEMBER');
        $this->assertNoInvitationCreated();
    }

    #[Test]
    public function when_the_email_already_has_a_live_pending_invitation_then_the_invite_is_rejected(): void
    {
        // Given an admin whose earlier invite to an email is still pending
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $this->invite($admin, $company, [
            'email' => 'pending@contoso.lk',
            'roleCode' => 'employee',
        ])->assertCreated();

        // When they invite the same email again
        $response = $this->invite($admin, $company, [
            'email' => 'pending@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then it is refused (the pending invitation stands — resend rotates it,
        // UC-1-021) and no second invitation row or email exists
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::AlreadyMember);
        $this->assertSame(1, Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', 'pending@contoso.lk')
            ->count());
        Mail::assertQueued(InvitationMail::class, 1);
    }

    #[Test]
    public function when_the_requested_role_is_owner_then_the_invite_is_rejected(): void
    {
        // Given an admin of a company
        [$company, $admin] = $this->aCompanyWithMember('admin');

        // When the admin invites a new email with the owner role
        $response = $this->invite($admin, $company, [
            'email' => 'newuser@contoso.lk',
            'roleCode' => 'owner',
        ]);

        // Then the owner role is refused as not invitable and nothing is created
        $this->assertValidationRejected($response, 'roleCode', 'The selected role code is invalid.');
        $this->assertNoInvitationCreated();
    }

    /**
     * @param  array<string, string>  $body
     */
    #[Test]
    #[DataProvider('provideInvalidInviteInputs')]
    public function when_the_invite_input_is_invalid_then_the_invite_is_rejected(array $body, string $invalidField, string $message): void
    {
        // Given an admin of a company
        [$company, $admin] = $this->aCompanyWithMember('admin');

        // When the admin submits an invite with invalid input
        $response = $this->invite($admin, $company, $body);

        // Then the input is refused as invalid and nothing is created
        $this->assertValidationRejected($response, $invalidField, $message);
        $this->assertNoInvitationCreated();
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function provideInvalidInviteInputs(): array
    {
        return [
            'malformed email' => [['email' => 'not-an-email', 'roleCode' => 'employee'], 'email', 'The email field must be a valid email address.'],
            'empty email' => [['email' => '', 'roleCode' => 'employee'], 'email', 'The email field is required.'],
            'over-long email' => [['email' => str_repeat('a', 64).'@'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', 60).'.'.str_repeat('e', 60).'.lk', 'roleCode' => 'employee'], 'email', 'The email field must not be greater than 255 characters.'],
            'missing role code' => [['email' => 'newuser@contoso.lk'], 'roleCode', 'The role code field is required.'],
            'unknown role code' => [['email' => 'newuser@contoso.lk', 'roleCode' => 'super_admin'], 'roleCode', 'The selected role code is invalid.'],
        ];
    }

    #[Test]
    #[DataProvider('provideNonInvitingRoles')]
    public function when_the_actor_role_lacks_the_invite_permission_then_the_invite_is_denied(string $roleCode): void
    {
        // Given a member whose role does not carry users.invite
        [$company, $actor] = $this->aCompanyWithMember($roleCode);

        // When they attempt to invite a new email
        $response = $this->invite($actor, $company, [
            'email' => 'newuser@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the invite is denied for the missing permission and nothing is created
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::RoleInsufficient);
        $this->assertNoInvitationCreated();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonInvitingRoles(): array
    {
        return [
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
        ];
    }

    #[Test]
    public function when_the_hourly_invite_allowance_is_spent_then_the_next_invite_is_throttled(): void
    {
        // Given an admin who has spent the full hourly invite allowance
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $this->exhaustInviteLimit($admin, $company);

        // When they attempt one more invite within the hour
        $response = $this->invite($admin, $company, [
            'email' => 'one-too-many@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the invite is throttled
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_the_invitation_projection_fails_then_no_invitation_is_recorded_or_mailed(): void
    {
        // Given an admin and an invitations projection that fails mid-invite
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $this->induceProjectorFailureFor(UserInvited::class);

        // When the admin invites a new email
        $response = $this->invite($admin, $company, [
            'email' => 'newhire@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the invite fails whole: no invitation, no recorded event, no email
        $this->assertRefusedWith($response, Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError);
        $this->assertNoInvitationCreated();
    }

    // --- Edge ---

    #[Test]
    public function when_a_previously_removed_member_is_reinvited_then_the_same_membership_is_reengaged(): void
    {
        // Given a former member whose company_user pivot was soft-deleted on removal
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $target = User::factory()->withEmail('returning@contoso.lk')->create();
        $membershipId = $this->aRemovedMembership($company, $target);

        // When the admin re-invites that email
        $response = $this->invite($admin, $company, [
            'email' => 'returning@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then a pending invitation reuses the SAME membership UUID and the pivot
        // stays removed until the invitee accepts (undelete happens at accept, UC-1-019)
        $response->assertCreated();
        $this->assertDatabaseHas('invitations', [
            'id' => $membershipId,
            'company_id' => $company->getKey(),
            'email' => 'returning@contoso.lk',
            'accepted_at' => null,
        ]);
        $this->assertSoftDeleted('company_user', ['id' => $membershipId]);
    }

    #[Test]
    public function when_a_pending_invitation_has_expired_then_the_email_can_be_reinvited(): void
    {
        // Given an invitation that expired without acceptance
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $this->invite($admin, $company, [
            'email' => 'slow@contoso.lk',
            'roleCode' => 'employee',
        ])->assertCreated();
        $this->travel(8)->days();

        // When the admin re-invites the same email
        $response = $this->invite($admin, $company, [
            'email' => 'slow@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then a FRESH pending invitation is issued (expiry is terminal — SM-1-01
        // T5: re-engagement is a new stream/row) and the expired row is preserved
        $response->assertCreated();
        $rows = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', 'slow@contoso.lk')
            ->orderBy('expires_at')
            ->get();
        $this->assertCount(2, $rows);
        [$expired, $fresh] = $rows->all();
        $this->assertTrue($expired->expires_at->isPast());
        $this->assertTrue($fresh->expires_at->isFuture());
        Mail::assertQueued(InvitationMail::class, 2);
    }

    #[Test]
    public function when_an_invitation_was_revoked_then_the_email_can_be_reinvited(): void
    {
        // Given an invitation that was revoked without acceptance (UC-1-022)
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $this->aRevokedInvitation($company, $admin, 'slow@contoso.lk');

        // When the admin re-invites the same email
        $response = $this->invite($admin, $company, [
            'email' => 'slow@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then a FRESH pending invitation is issued (revocation is terminal —
        // SM-1-01 T6: re-engagement is a new stream/row, mirroring expiry T5) and
        // the revoked row is preserved with its audit-forensics stamps intact
        $response->assertCreated();
        $rows = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', 'slow@contoso.lk')
            ->orderBy('created_at')
            ->get();
        $this->assertCount(2, $rows);
        [$revoked, $fresh] = $rows->all();
        $this->assertNotNull($revoked->revoked_at);
        $this->assertNull($fresh->revoked_at);
        $this->assertNull($fresh->accepted_at);
        $this->assertTrue($fresh->expires_at->isFuture());
    }

    #[Test]
    public function when_a_removed_member_is_reinvited_after_a_revoked_reinvite_then_the_row_is_clean_pending(): void
    {
        // Given a removed member whose re-invite was itself revoked — the reused
        // D5b row carries all three revoke stamps (the terminal-marker re-entry chain)
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $membershipId = $this->aRevokedReinviteOfARemovedMember($company, $admin, 'returning@contoso.lk');

        // When the admin re-invites that email once more
        $response = $this->invite($admin, $company, [
            'email' => 'returning@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the reused row returns to clean pending — every terminal marker reset
        $response->assertCreated();
        $this->assertReinvitedRowCleanPending($membershipId, $admin, $company);
    }

    #[Test]
    public function when_the_reinvite_after_a_revoked_reinvite_is_accepted_then_the_membership_reactivates(): void
    {
        // Given a fresh invitation re-issued on the once-revoked reused row
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $membershipId = $this->aRevokedReinviteOfARemovedMember($company, $admin, 'returning@contoso.lk');
        $token = $this->aPendingInvitation($company, $admin, 'returning@contoso.lk');
        $joiner = User::query()->where('email', 'returning@contoso.lk')->sole();

        // When the returning member accepts with the fresh token
        $response = $this->acceptInvitation($token, [], $joiner);

        // Then the accept succeeds and the membership is live again on the same
        // row — a stale revoked_at would have refused the token as invalid
        $response->assertOk();
        $this->assertMemberStillActive($membershipId);
        $this->assertNotNull(Invitation::query()->whereKey($membershipId)->sole()->accepted_at);
    }

    // --- Happy ---

    #[Test]
    #[DataProvider('provideInvitingRoles')]
    public function when_an_authorized_role_invites_a_new_email_then_a_pending_invitation_is_created(string $roleCode): void
    {
        // Given a member holding a role that may invite
        [$company, $actor] = $this->aCompanyWithMember($roleCode);

        // When they invite a brand-new email
        $response = $this->invite($actor, $company, [
            'email' => 'newuser@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then a pending invitation is stored (hashed token at rest, 7-day expiry,
        // event-sourced) and the invite email is queued with the plaintext accept-link
        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['id', 'email', 'role', 'expires_at']]);
        $this->assertPendingInvitationCreated($company, 'newuser@contoso.lk', 'employee');
        $this->assertInvitationMailQueued($company, 'newuser@contoso.lk');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvitingRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
        ];
    }

    #[Test]
    public function when_an_existing_nonmember_user_is_invited_then_a_pending_invitation_is_created_without_a_membership(): void
    {
        // Given an existing user who is not a member of the company
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $alice = User::factory()->withEmail('alice@example.lk')->create();

        // When the admin invites alice's email
        $response = $this->invite($admin, $company, [
            'email' => 'alice@example.lk',
            'roleCode' => 'employee',
        ]);

        // Then a pending invitation exists but NO membership pivot — alice joins
        // only by accepting while authenticated (D7; the pivot lands at UC-1-019)
        $response->assertCreated();
        $this->assertPendingInvitationCreated($company, 'alice@example.lk', 'employee');
        $this->assertInvitationMailQueued($company, 'alice@example.lk');
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $company->getKey(),
            'user_id' => $alice->getKey(),
        ]);
    }

    #[Test]
    public function when_a_multicompany_admin_invites_then_the_invitation_is_scoped_to_the_context_company(): void
    {
        // Given an admin of two companies, acting in the SECOND company's context
        // (so resolving the actor's first company instead of the request context
        // would be caught)
        [$companyA, $admin] = $this->aCompanyWithMember('admin');
        $companyB = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $this->attachMember($companyB, $admin, $this->seededRole('admin'));

        // When they invite a new email under company B's context
        $response = $this->invite($admin, $companyB, [
            'email' => 'newuser@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the invitation belongs to company B alone and records the inviter
        $response->assertCreated();
        $this->assertPendingInvitationCreated($companyB, 'newuser@contoso.lk', 'employee');
        $this->assertDatabaseMissing('invitations', ['company_id' => $companyA->getKey()]);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserInvited::class)
            ->where('event_properties->companyId', $companyB->getKey())
            ->where('event_properties->invitedBy', $admin->getKey())
            ->count());
    }

    /**
     * The full terminal-marker re-entry chain (OQ-1-129): a member accepted, was
     * removed, was re-invited onto the SAME row (D5b), and that re-invite was
     * revoked WITH a reason — leaving all three revoke stamps set on the reused
     * row. Returns the membership id the next re-invite must scrub clean.
     */
    private function aRevokedReinviteOfARemovedMember(Company $company, User $actor, string $email): string
    {
        [, $membershipId] = $this->anAcceptedMembership($company, $actor, $email);
        $this->removeMember($actor, $company, $membershipId)->assertNoContent();
        $this->aPendingInvitation($company, $actor, $email);
        $this->reason = 'Position filled';
        $this->revokeInvitationWithReason($actor, $company, $membershipId)->assertNoContent();
        Auth::forgetGuards();

        return $membershipId;
    }

    /**
     * The reused row is clean pending again — every terminal marker (accept,
     * expiry, and the revoke trio) is null — and the roster's pending arm lists
     * it: a stale revoked_at would hide the row from the roster's whereNull
     * filter and block the invitee's accept.
     */
    private function assertReinvitedRowCleanPending(string $membershipId, User $actor, Company $company): void
    {
        $this->assertDatabaseHas('invitations', [
            'id' => $membershipId,
            'accepted_at' => null,
            'expired_at' => null,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revocation_reason' => null,
        ]);

        $roster = $this->viewMemberships($actor, $company);
        $roster->assertOk();
        $this->assertContains(
            $membershipId,
            $roster->collect('data')->pluck('id')->all(),
            'The re-invited pending row must appear in the roster.'
        );
    }
}
