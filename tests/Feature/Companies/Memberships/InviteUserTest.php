<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Companies\Memberships\Events\UserInvited;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Mail\InvitationMail;
use App\Companies\Memberships\Services\InvitationAllowance;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class InviteUserTest extends MembershipsTestCase
{
    use FreeTierCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeFreeTierCompany();
    }

    // --- Sad ---

    #[Test]
    public function when_the_email_is_already_an_active_member_then_the_invite_is_rejected(): void
    {
        // Given an admin of a company and a target who is already a member
        $target = User::factory()->withEmail('member@contoso.lk')->create();
        $this->attachMember($this->company, $target, $this->seededRole('employee'));

        // When the admin invites that member's email again
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'member@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then it is refused as an existing member and nothing is created or audited
        $response->assertUnprocessable();
        $response->assertJsonPath('error_code', 'ALREADY_MEMBER');
        $this->assertNoInvitationCreated();
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
    }

    #[Test]
    public function when_the_email_already_has_a_live_pending_invitation_then_the_invite_is_rejected(): void
    {
        // Given an admin whose earlier invite to an email is still pending
        $this->invite($this->admin, $this->company, [
            'email' => 'pending@contoso.lk',
            'roleCode' => 'employee',
        ])->assertCreated();

        // When they invite the same email again
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'pending@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then it is refused (the pending invitation stands — resend rotates it,
        // UC-1-021) and no second invitation row or email exists
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::AlreadyMember);
        $this->assertSame(1, Invitation::query()
            ->where('company_id', $this->company->getKey())
            ->where('email', 'pending@contoso.lk')
            ->count());
        Mail::assertQueued(InvitationMail::class, 1);
    }

    #[Test]
    public function when_the_requested_role_is_owner_then_the_invite_is_rejected(): void
    {
        // Given an admin of a company

        // When the admin invites a new email with the owner role
        $response = $this->invite($this->admin, $this->company, [
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

        // When the admin submits an invite with invalid input
        $response = $this->invite($this->admin, $this->company, $body);

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
        $actor = $this->memberWithRole($roleCode);

        // When they attempt to invite a new email
        $response = $this->invite($actor, $this->company, [
            'email' => 'newuser@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the invite is denied for the missing permission and nothing is created
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::RoleInsufficient);
        $this->assertNoInvitationCreated();
    }

    #[Test]
    public function when_invites_fail_validation_then_they_spend_no_allowance(): void
    {
        // Given an admin whose twenty invites were all refused as malformed
        for ($i = 0; $i < InvitationAllowance::PER_HOUR; $i++) {
            $this->invite($this->admin, $this->company, ['email' => 'not-an-email', 'roleCode' => 'employee']);
        }

        // When they send a well-formed invite within the hour
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'well-formed@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then it is sent, the malformed ones having cost nothing
        $response->assertCreated();
        $this->assertDatabaseCount('invitations', 1);
    }

    #[Test]
    public function when_the_hourly_invite_allowance_is_spent_then_the_next_invite_is_throttled(): void
    {
        // Given an admin who has spent the full hourly invite allowance
        $this->exhaustInviteLimit($this->admin, $this->company);

        // When they attempt one more invite within the hour
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'one-too-many@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the invite is throttled
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_another_admin_has_spent_their_allowance_then_this_admin_can_still_invite(): void
    {
        // Given two admins of one company, the first of whom has spent the hourly allowance
        $otherAdmin = $this->aCompanyMember($this->company, 'admin');
        $this->exhaustInviteLimit($this->admin, $this->company);

        // When the second admin sends an invite within the hour
        $response = $this->invite($otherAdmin, $this->company, [
            'email' => 'from-the-other-admin@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then it is sent — each admin has their own allowance
        $response->assertCreated();
    }

    #[Test]
    public function when_an_invite_is_refused_as_already_member_then_it_still_spends_the_allowance(): void
    {
        // Given an admin who has sent nineteen invites this hour
        $member = User::factory()->withEmail('member@contoso.lk')->create();
        $this->attachMember($this->company, $member, $this->seededRole('employee'));
        $this->exhaustInviteLimit($this->admin, $this->company, InvitationAllowance::PER_HOUR - 1);
        // And whose twentieth invited an existing member
        $refused = $this->invite($this->admin, $this->company, ['email' => 'member@contoso.lk', 'roleCode' => 'employee']);

        // When they send one more invite within the hour
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'one-too-many@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then it is throttled — the refused invite was spent
        $this->assertThrottled($response);
        // And that invite had been refused as an existing member, not throttled
        $this->assertRefusedWith($refused, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::AlreadyMember);
    }

    #[Test]
    public function when_the_invitation_projection_fails_then_no_invitation_is_recorded_or_mailed(): void
    {
        // Given an admin and an invitations projection that fails mid-invite
        $this->induceProjectorFailureFor(UserInvited::class);

        // When the admin invites a new email
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'newhire@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the invite fails whole: no invitation, no recorded event, no email
        $this->assertRefusedWith($response, Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError);
        $this->assertNoInvitationCreated();
    }

    #[Test]
    public function when_the_audit_row_cannot_be_written_then_no_invitation_is_recorded_or_mailed(): void
    {
        // Given an admin and an audit trail that refuses the row
        $this->failTheAuditTrail();

        // When the admin invites a new email
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'newhire@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the invite fails whole: no invitation, no recorded event, no email, no row
        $this->assertRefusedWith($response, Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError);
        $this->assertNoInvitationCreated();
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
    }

    // --- Edge ---

    #[Test]
    public function when_the_body_names_another_company_then_the_invite_stays_in_the_context_company(): void
    {
        // Given an admin of one company and another company's id in the request body
        $other = Company::factory()->onPlan(BillingPlan::Pro)->create();

        // When the admin invites a new email
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'newhire@contoso.lk',
            'roleCode' => 'employee',
            'company' => $other->getKey(),
        ]);

        // Then the invitation and its audit row belong to the context company alone
        $response->assertCreated();
        $this->assertDatabaseHas('invitations', ['email' => 'newhire@contoso.lk', 'company_id' => $this->company->getKey()]);
        $this->assertDatabaseHas('audit_trail', ['action' => 'user_invited', 'company_id' => $this->company->getKey()]);
    }

    #[Test]
    public function when_a_removed_member_is_reinvited_then_the_audit_row_links_the_new_invitation(): void
    {
        // Given a former member whose membership stream already holds an earlier invite
        [, $membershipId] = $this->anAcceptedMembership($this->company, $this->admin, 'returning@contoso.lk');
        $this->removeMember($this->admin, $this->company, $membershipId)->assertNoContent();
        Auth::forgetGuards();
        $this->fromADevice();

        // When the admin re-invites that email
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'returning@contoso.lk',
            'roleCode' => 'hr_officer',
        ]);

        // Then the row names the re-invite and links its own event, the stream's newest
        $response->assertCreated();
        $this->assertInvitationAudited($response, Invitation::query()->findOrFail($membershipId), AuditAction::UserInvited);
    }

    #[Test]
    public function when_a_previously_removed_member_is_reinvited_then_the_same_membership_is_reengaged(): void
    {
        // Given a former member whose company_user pivot was soft-deleted on removal
        $target = User::factory()->withEmail('returning@contoso.lk')->create();
        $membershipId = $this->aRemovedMembership($this->company, $target);

        // When the admin re-invites that email
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'returning@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then a pending invitation reuses the SAME membership UUID and the pivot
        // stays removed until the invitee accepts (undelete happens at accept, UC-1-019)
        $response->assertCreated();
        $this->assertDatabaseHas('invitations', [
            'id' => $membershipId,
            'company_id' => $this->company->getKey(),
            'email' => 'returning@contoso.lk',
            'accepted_at' => null,
        ]);
        $this->assertSoftDeleted('company_user', ['id' => $membershipId]);
    }

    #[Test]
    public function when_a_pending_invitation_has_expired_then_the_email_can_be_reinvited(): void
    {
        // Given an invitation that expired without acceptance
        $this->invite($this->admin, $this->company, [
            'email' => 'slow@contoso.lk',
            'roleCode' => 'employee',
        ])->assertCreated();
        $this->travel(8)->days();

        // When the admin re-invites the same email
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'slow@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then a FRESH pending invitation is issued (expiry is terminal — SM-1-01
        // T5: re-engagement is a new stream/row) and the expired row is preserved
        $response->assertCreated();
        $rows = Invitation::query()
            ->where('company_id', $this->company->getKey())
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
        $this->aRevokedInvitation($this->company, $this->admin, 'slow@contoso.lk');

        // When the admin re-invites the same email
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'slow@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then a FRESH pending invitation is issued (revocation is terminal —
        // SM-1-01 T6: re-engagement is a new stream/row, mirroring expiry T5) and
        // the revoked row is preserved with its audit-forensics stamps intact
        $response->assertCreated();
        $rows = Invitation::query()
            ->where('company_id', $this->company->getKey())
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
        $membershipId = $this->aRevokedReinviteOfARemovedMember($this->company, $this->admin, 'returning@contoso.lk');

        // When the admin re-invites that email once more
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'returning@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the reused row returns to clean pending — every terminal marker reset
        $response->assertCreated();
        $this->assertReinvitedRowCleanPending($membershipId, $this->admin, $this->company);
    }

    #[Test]
    public function when_the_reinvite_after_a_revoked_reinvite_is_accepted_then_the_membership_reactivates(): void
    {
        // Given a fresh invitation re-issued on the once-revoked reused row
        $membershipId = $this->aRevokedReinviteOfARemovedMember($this->company, $this->admin, 'returning@contoso.lk');
        $token = $this->aPendingInvitation($this->company, $this->admin, 'returning@contoso.lk');
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
        $actor = $this->memberWithRole($roleCode);

        // When they invite a brand-new email
        $response = $this->invite($actor, $this->company, [
            'email' => 'newuser@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then a pending invitation is stored (hashed token at rest, 7-day expiry,
        // event-sourced) and the invite email is queued with the plaintext accept-link
        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['id', 'email', 'role', 'expires_at']]);
        $this->assertPendingInvitationCreated($this->company, 'newuser@contoso.lk', 'employee');
        $this->assertInvitationMailQueued($this->company, 'newuser@contoso.lk');
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
        $alice = User::factory()->withEmail('alice@example.lk')->create();

        // When the admin invites alice's email
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'alice@example.lk',
            'roleCode' => 'employee',
        ]);

        // Then a pending invitation exists but NO membership pivot — alice joins
        // only by accepting while authenticated (D7; the pivot lands at UC-1-019)
        $response->assertCreated();
        $this->assertPendingInvitationCreated($this->company, 'alice@example.lk', 'employee');
        $this->assertInvitationMailQueued($this->company, 'alice@example.lk');
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $this->company->getKey(),
            'user_id' => $alice->getKey(),
        ]);
    }

    #[Test]
    public function when_a_multicompany_admin_invites_then_the_invitation_is_scoped_to_the_context_company(): void
    {
        // Given an admin of two companies, acting in the SECOND company's context
        // (so resolving the actor's first company instead of the request context
        // would be caught)
        $companyB = Company::factory()->onPlan(BillingPlan::Pro)->create();
        $this->attachMember($companyB, $this->admin, $this->seededRole('admin'));

        // When they invite a new email under company B's context
        $response = $this->invite($this->admin, $companyB, [
            'email' => 'newuser@contoso.lk',
            'roleCode' => 'employee',
        ]);

        // Then the invitation belongs to company B alone and records the inviter
        $response->assertCreated();
        $this->assertPendingInvitationCreated($companyB, 'newuser@contoso.lk', 'employee');
        $this->assertDatabaseMissing('invitations', ['company_id' => $this->company->getKey()]);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserInvited::class)
            ->where('event_properties->companyId', $companyB->getKey())
            ->where('event_properties->invitedBy', $this->admin->getKey())
            ->count());
    }

    #[Test]
    public function when_an_invite_is_sent_then_the_audit_trail_records_who_was_invited_as_what(): void
    {
        // Given an admin acting from a known device
        $this->fromADevice();

        // When the admin invites a new email as an HR officer
        $response = $this->invite($this->admin, $this->company, [
            'email' => 'newhire@contoso.lk',
            'roleCode' => 'hr_officer',
        ]);

        // Then the audit trail records the invitation, who sent it and from where
        $response->assertCreated();
        $this->assertInvitationAudited($response, Invitation::query()->sole(), AuditAction::UserInvited);
        $this->assertActedBy(AuditAction::UserInvited, $this->admin, 'admin');
    }

    /**
     * The full terminal-marker re-entry chain (OQ-1-129): a member accepted, was
     * removed, was re-invited onto the SAME row (D5b), and that re-invite was
     * revoked WITH a reason — leaving all three revoke stamps set on the reused
     * row. Returns the membership id the next re-invite must scrub clean.
     */
    protected function aRevokedReinviteOfARemovedMember(Company $company, User $actor, string $email): string
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
    protected function assertReinvitedRowCleanPending(string $membershipId, User $actor, Company $company): void
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
