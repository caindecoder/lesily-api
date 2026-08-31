<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Events\UserJoinedCompany;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Notifications\Entities\NotificationPreference;
use App\Foundation\Auth\Exceptions\UserAlreadyRegisteredException;
use App\Companies\Memberships\Exceptions\InvalidInvitationException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\Memberships\UseCases\Commands\AcceptInvitationCommand;
use App\Companies\Memberships\UseCases\Commands\CreateInvitationCommand;
use App\Foundation\Auth\UseCases\Commands\CreateUserFromInvitationCommand;
use App\Companies\Memberships\Exceptions\InvitationAcceptContentionException;

/**
 * UC-1-019 — Accept Company Invitation (membership slice #7, Task 3).
 *
 * The invitee follows the emailed accept-link: a NEW email creates the user
 * account (genesis) + joins; an EXISTING email must be authenticated first
 * (R9/D7 divergence from LeaveDates' token-sufficient accept). All token
 * failures collapse to one INVITE_INVALID envelope (H4 — no token-state leak).
 */
class AcceptInvitationTest extends MembershipsTestCase
{
    protected Company $company;

    protected User $actor;

    protected User $invitee;

    #[Test]
    public function when_the_token_is_unknown_then_acceptance_is_refused(): void
    {
        // Given — no invitation exists; a would-be joiner supplies valid details
        $body = $this->validNewUserBody();

        // When — accepting with a token that was never issued
        $response = $this->acceptInvitation('never-issued-token-0000000000000000000000', $body);

        // Then — the collapsed invite-invalid envelope; no state was created
        $this->assertRefusedWith($response, ApiErrorCode::InviteInvalid);
        $this->assertNothingAccepted();
    }

    #[Test]
    public function when_the_invitation_has_expired_then_acceptance_is_refused(): void
    {
        // Given — a pending invitation whose 7-day window has elapsed
        [$company, $actor] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $actor, 'kasun@newjoiner.lk');
        $this->travel(8)->days();

        // When — the invitee follows the stale link with valid details
        $response = $this->acceptInvitation($token, $this->validNewUserBody());

        // Then — the SAME collapsed envelope (no expired-vs-unknown leak, H4);
        // the invitation stays unconsumed and no account was created
        $this->assertRefusedWith($response, ApiErrorCode::InviteInvalid);
        $this->assertInvitationNotConsumed($company, 'kasun@newjoiner.lk');
        $this->assertDatabaseMissing('users', ['email' => 'kasun@newjoiner.lk']);
    }

    #[Test]
    public function when_the_invitation_was_revoked_then_acceptance_is_refused(): void
    {
        // Given — a pending invitation the admin then revoked (UC-1-022); the
        // original link is dead thereafter
        [$company, $actor] = $this->aCompanyWithMember();
        $deadToken = $this->aRevokedInvitation($company, $actor, 'kasun@newjoiner.lk');

        // When — the invitee follows the revoked link with valid details
        $response = $this->acceptInvitation($deadToken, $this->validNewUserBody());

        // Then — the SAME collapsed envelope (no revoked-vs-unknown leak, H4);
        // the invitation stays unconsumed and no account was created
        $this->assertRefusedWith($response, ApiErrorCode::InviteInvalid);
        $this->assertInvitationNotConsumed($company, 'kasun@newjoiner.lk');
        $this->assertDatabaseMissing('users', ['email' => 'kasun@newjoiner.lk']);
    }

    #[Test]
    public function when_an_accepted_invitation_is_replayed_then_it_is_refused(): void
    {
        // Given — an invitation already consumed by its new user
        [$company, $actor] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $actor, 'kasun@newjoiner.lk');
        $this->acceptInvitation($token, $this->validNewUserBody())->assertCreated();
        Auth::forgetGuards();

        // When — the same token is replayed
        $response = $this->acceptInvitation($token, $this->validNewUserBody());

        // Then — the SAME collapsed envelope (a used token is indistinguishable
        // from an unknown one — H4); the joiner still holds exactly ONE membership
        $this->assertRefusedWith($response, ApiErrorCode::InviteInvalid);
        $this->assertSame(1, CompanyUser::withTrashed()
            ->whereIn('user_id', User::query()->where('email', 'kasun@newjoiner.lk')->pluck('id'))
            ->count());
    }

    #[Test]
    public function when_an_existing_users_invitation_is_accepted_as_guest_then_authentication_is_required(): void
    {
        // Given — the invitee already has an account (a multi-company joiner)
        [$company, $actor] = $this->aCompanyWithMember();
        User::factory()->withEmail('anushka@member.lk')->create();
        $token = $this->aPendingInvitation($company, $actor, 'anushka@member.lk');

        // When — the invitation is accepted without logging in
        $response = $this->acceptInvitation($token);

        // Then — authentication is demanded first (R9/D7); nothing joined
        $this->assertRefusedWith($response, ApiErrorCode::Unauthenticated);
        $this->assertInvitationNotConsumed($company, 'anushka@member.lk');
    }

    #[Test]
    public function when_the_authenticated_user_is_not_the_invitee_then_acceptance_is_refused(): void
    {
        // Given — an invitation for one account, a session belonging to another
        [$company, $actor] = $this->aCompanyWithMember();
        User::factory()->withEmail('anushka@member.lk')->create();
        $someoneElse = User::factory()->withEmail('other@member.lk')->create();
        $token = $this->aPendingInvitation($company, $actor, 'anushka@member.lk');

        // When — the wrong account tries to consume the invitation
        $response = $this->acceptInvitation($token, [], $someoneElse);

        // Then — only the invited email may accept (UC alt-4b); nothing joined
        $response->assertStatus(403);
        $this->assertRefusedWith($response, ApiErrorCode::InviteEmailMismatch);
        $this->assertInvitationNotConsumed($company, 'anushka@member.lk');
    }

    /**
     * @param  array<string, string>  $body
     */
    #[Test, DataProvider('invalidNewUserDetailsProvider')]
    public function when_a_new_users_registration_details_are_invalid_then_no_account_is_created(array $body, string $field, string $message): void
    {
        // Given — a pending invitation for a brand-new email
        [$company, $actor] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $actor, 'kasun@newjoiner.lk');

        // When — accepting with a broken registration body
        $response = $this->acceptInvitation($token, $body);

        // Then — shape-rejected on the offending field; nothing was created
        $this->assertValidationRejected($response, $field, $message);
        $this->assertInvitationNotConsumed($company, 'kasun@newjoiner.lk');
        $this->assertDatabaseMissing('users', ['email' => 'kasun@newjoiner.lk']);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function invalidNewUserDetailsProvider(): array
    {
        return [
            'password missing' => [['name' => 'Nimal Perera'], 'password', 'A password is required to create your account.'],
            'password empty' => [['name' => 'Nimal Perera', 'password' => ''], 'password', 'A password is required to create your account.'],
            'password below the 8-char minimum' => [['name' => 'Nimal Perera', 'password' => 'Short7!'], 'password', 'The password field must be at least 8 characters.'],
            'name missing' => [['password' => 'Str0ng-Pass!'], 'name', 'Your name is required to create your account.'],
            'name whitespace only' => [['name' => '   ', 'password' => 'Str0ng-Pass!'], 'name', 'Your name is required to create your account.'],
            'name beyond the 255-char column' => [['name' => str_repeat('a', 256), 'password' => 'Str0ng-Pass!'], 'name', 'The name field must not be greater than 255 characters.'],
        ];
    }

    #[Test]
    public function when_too_many_accept_attempts_arrive_then_they_are_throttled(): void
    {
        // Given — the shared public-auth allowance (10/min per IP) spent on
        // token-guessing attempts
        for ($i = 0; $i < 10; $i++) {
            $this->acceptInvitation("guessed-token-{$i}")->assertStatus(422);
        }

        // When — one more attempt arrives from the same IP
        $response = $this->acceptInvitation('guessed-token-final');

        // Then — throttled with retry guidance (the token-guessing brake)
        $this->assertThrottled($response);
    }

    #[Test]
    public function when_a_removed_member_accepts_a_leftover_invitation_then_acceptance_is_refused(): void
    {
        // Given — a member removed from the company, plus a leftover pending
        // invitation for their email on a DIFFERENT stream (the OQ-1-126 race
        // artifact, seeded through the guardless aggregate); their trashed
        // pivot still occupies the (company, user) unique slot
        [$company, $actor] = $this->aCompanyWithMember();
        $removed = User::factory()->withEmail('ruwan@member.lk')->create();
        $membershipId = $this->aRemovedMembership($company, $removed);
        $plaintext = Str::random(40);
        UserMembership::retrieve((string) Str::uuid7())
            ->invite(CreateInvitationCommand::from([
                'companyId' => $company->getKey(),
                'email' => 'ruwan@member.lk',
                'roleId' => $this->seededRole('employee')->getKey(),
                'invitedByUserId' => $actor->getKey(),
                'plaintextToken' => $plaintext,
            ]))
            ->persist();

        // When — the removed member accepts the leftover invitation while
        // authenticated
        $response = $this->acceptInvitation($plaintext, [], $removed);

        // Then — refused with the collapsed envelope (H4; OQ-1-162): a leftover
        // invitation cannot resurrect a removed member — re-entry is the
        // deliberate same-UUID re-invite — and the removal stays intact
        $this->assertRefusedWith($response, ApiErrorCode::InviteInvalid);
        $this->assertRefusalLeftMemberRemoved($company, 'ruwan@member.lk', $membershipId);
    }

    /**
     * @param  'consumed'|'revoked'|'expired'|'superseded'  $death
     */
    #[Test, DataProvider('invitationDeathProvider')]
    public function when_the_invitation_died_after_the_first_look_then_the_late_accept_is_refused(string $death): void
    {
        // Given — an accept holding a STALE snapshot of the invitation (its
        // pre-transaction look) while the row moves on before the locked
        // re-read; the seam feeds the snapshot, the re-check is production
        [$this->company, $this->actor] = $this->aCompanyWithMember();
        $this->invitee = User::factory()->withEmail('anushka@member.lk')->create();
        $token = $this->aPendingInvitation($this->company, $this->actor, 'anushka@member.lk');
        $staleSnapshot = Invitation::query()->where('email', 'anushka@member.lk')->sole();
        $this->applyInvitationDeath($death, $token);
        $joinsBefore = LesilyStoredEvent::query()->where('event_class', UserJoinedCompany::class)->count();
        $pivotsBefore = CompanyUser::withTrashed()->where('company_id', $this->company->getKey())->count();

        // When — the stale accept reaches the transaction, every statement recorded
        $capture = $this->recordedQueriesDuring(function () use ($staleSnapshot, $token): void {
            try {
                $this->staleSnapshotAccept($staleSnapshot)->execute(
                    AcceptInvitationCommand::validateAndCreate(['token' => $token]),
                    $this->invitee,
                    'web',
                );
                $this->fail('The locked re-check must refuse a dead invitation.');
            } catch (InvalidInvitationException) {
                // Then — the in-transaction locked re-check refused the late accept
            }
        });

        // Then — the re-check ran under the lock and nothing was written
        $this->assertLockedInvitationReadRecorded($capture, (string) $staleSnapshot->getKey());
        $this->assertSame($joinsBefore, LesilyStoredEvent::query()->where('event_class', UserJoinedCompany::class)->count());
        $this->assertSame($pivotsBefore, CompanyUser::withTrashed()->where('company_id', $this->company->getKey())->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invitationDeathProvider(): array
    {
        return [
            'consumed by a concurrent accept' => ['consumed'],
            'revoked while the accept was in flight' => ['revoked'],
            'expired while the accept was in flight' => ['expired'],
            'superseded by a resend while the accept was in flight' => ['superseded'],
        ];
    }

    /**
     * Move the invitation past the stale snapshot the way a concurrent writer
     * would: consume it, revoke it, let it expire, or rotate its token.
     *
     * @param  'consumed'|'revoked'|'expired'|'superseded'  $death
     */
    protected function applyInvitationDeath(string $death, string $token): void
    {
        match ($death) {
            'consumed' => $this->acceptInvitation($token, [], $this->invitee)->assertOk(),
            'revoked' => $this->revokeInvitation($this->actor, $this->company,
                (string) Invitation::query()->where('email', $this->invitee->email)->sole()->getKey())->assertNoContent(),
            'expired' => $this->travel(8)->days(),
            'superseded' => $this->resendInvitationAsAdmin(),
        };

        Auth::forgetGuards();
    }

    /**
     * Rotate the invitation's token through the resend endpoint (UC-1-021) —
     * the live-state mutation that leaves the ROW pending but the presented
     * plaintext dead.
     */
    protected function resendInvitationAsAdmin(): void
    {
        Sanctum::actingAs($this->actor);
        $invitationId = Invitation::query()->where('email', $this->invitee->email)->sole()->getKey();
        $this->postJson("/api/v1/invitations/{$invitationId}/resend", [], ['X-Company-Context' => $this->company->getKey()])
            ->assertSuccessful();
    }

    #[Test]
    public function when_the_accept_loses_contention_then_the_cause_and_family_message_survive(): void
    {
        // Given — an existing account's invitation and a lock-wait death
        // inside the accept transaction
        Exceptions::fake();
        [$company, $actor] = $this->aCompanyWithMember();
        $invitee = User::factory()->withEmail('anushka@member.lk')->create();
        $token = $this->aPendingInvitation($company, $actor, 'anushka@member.lk');
        $this->induceProjectorFailureFor(UserJoinedCompany::class, $this->syntheticQueryException(
            ['sqlstate' => 'HY000', 'code' => 1205, 'message' => 'SQLSTATE[HY000]: General error: 1205'],
        ));

        // When — the invitee accepts while authenticated
        $response = $this->acceptInvitation($token, [], $invitee);

        // Then — the conflict speaks with the INVITATION's voice (never a
        // sibling family's) and the driver cause rides the reported exception
        $this->assertRefusedWith($response, ApiErrorCode::StateGuardViolation);
        $response->assertJsonPath('message', 'Your invitation is being processed by another request. Please retry.');
        Exceptions::assertReported(
            fn (InvitationAcceptContentionException $e): bool => $e->getPrevious() instanceof QueryException
        );
    }

    #[Test]
    public function when_the_join_write_fails_then_the_whole_accept_rolls_back(): void
    {
        // Given — an existing account's invitation and a write that fails
        // AFTER the projector ran, inside the accept transaction
        [$company, $actor] = $this->aCompanyWithMember();
        $invitee = User::factory()->withEmail('anushka@member.lk')->create();
        $token = $this->aPendingInvitation($company, $actor, 'anushka@member.lk');
        $this->induceProjectorFailureFor(UserJoinedCompany::class, new RuntimeException('induced join failure'));

        // When — the invitee accepts while authenticated
        $response = $this->acceptInvitation($token, [], $invitee);

        // Then — the whole accept rolls back: nothing consumed, no membership
        $this->assertRefusedWith($response, ApiErrorCode::InternalError);
        $this->assertInvitationNotConsumed($company, 'anushka@member.lk');
    }

    #[Test]
    public function when_contention_strikes_after_the_accept_committed_then_it_is_not_disguised_as_retryable(): void
    {
        // Given — a new user's invitation, with contention striking the
        // POST-COMMIT write: the account and membership are already durable,
        // so a "please retry" would send the invitee to a consumed link
        [$company, $actor] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $actor, 'kasun@newjoiner.lk');
        User::updated(fn () => throw $this->syntheticQueryException(
            ['sqlstate' => 'HY000', 'code' => 1205, 'message' => 'SQLSTATE[HY000]: General error: 1205'],
        ));

        // When — the invitee accepts with valid registration details
        $response = $this->acceptInvitation($token, $this->validNewUserBody());

        // Then — a plain failure, never the 409 retry hint; the membership
        // itself is already durable
        $this->assertRefusedWith($response, ApiErrorCode::InternalError);
        $this->assertMembershipActivated($company, 'kasun@newjoiner.lk');
    }

    /**
     * @param  array{sqlstate: string, code: int, message: string}  $failure
     */
    #[Test]
    #[DataProvider('contentionProvider')]
    public function when_the_accept_hits_lock_contention_then_it_is_refused_as_a_conflict(array $failure, ApiErrorCode $expected): void
    {
        // Given an existing account's invitation and a write inside the
        // accept transaction that dies the way the driver reports it
        [$company, $actor] = $this->aCompanyWithMember();
        $invitee = User::factory()->withEmail('anushka@member.lk')->create();
        $token = $this->aPendingInvitation($company, $actor, 'anushka@member.lk');
        $this->induceProjectorFailureFor(UserJoinedCompany::class, $this->syntheticQueryException($failure));

        // When — the invitee accepts while authenticated
        $response = $this->acceptInvitation($token, [], $invitee);

        // Then contention answers as a conflict; anything else passes through
        $this->assertRefusedWith($response, $expected);
    }

    #[Test]
    public function when_a_new_users_invitation_died_after_the_first_look_then_no_account_is_created(): void
    {
        // Given — a brand-new email's invitation, revoked after the accept's
        // first look (the stale-snapshot seam on the genesis branch)
        [$company, $actor] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $actor, 'kasun@newjoiner.lk');
        $staleSnapshot = Invitation::query()->where('email', 'kasun@newjoiner.lk')->sole();
        $this->revokeInvitation($actor, $company, (string) $staleSnapshot->getKey())->assertNoContent();
        Auth::forgetGuards();

        // When — the stale genesis accept reaches the transaction
        try {
            $this->staleSnapshotAccept($staleSnapshot)->execute(
                AcceptInvitationCommand::validateAndCreate([
                    'token' => $token,
                    'name' => 'Nimal Perera',
                    'password' => 'Str0ng-Pass!',
                ]),
                null,
                'web',
            );
            $this->fail('The locked re-check must refuse the revoked invitation before provisioning.');
        } catch (InvalidInvitationException) {
            // Then — refused inside the transaction, before any account write
        }

        // Then — no account was provisioned and nothing was consumed
        $this->assertDatabaseMissing('users', ['email' => 'kasun@newjoiner.lk']);
        $this->assertInvitationNotConsumed($company, 'kasun@newjoiner.lk');
    }

    #[Test]
    public function when_an_existing_user_accepts_with_blank_optional_fields_then_their_membership_activates(): void
    {
        // Given — an account already on the platform, invited to a company it
        // doesn't belong to, whose client sends the new-user fields blank
        [$company, $actor] = $this->aCompanyWithMember();
        $invitee = User::factory()->withEmail('anushka@member.lk')->create();
        $token = $this->aPendingInvitation($company, $actor, 'anushka@member.lk');

        // When — they accept while authenticated, with a blank name and password
        $response = $this->acceptInvitation($token, ['name' => '', 'password' => ''], $invitee);

        // Then — the blanks are optional on this branch: the membership activates and is returned
        $this->assertMembershipReturned($response, $invitee, 'employee');
        $this->assertMembershipActivated($company, 'anushka@member.lk');
    }

    #[Test]
    public function when_the_invitee_has_opted_out_of_mail_then_the_team_welcome_is_not_sent(): void
    {
        // Given — an existing account that opted its email out of team-welcome
        // notices (non-critical, so the opt-out binds; questionnaire S16)
        [$company, $actor] = $this->aCompanyWithMember();
        $invitee = User::factory()->withEmail('anushka@member.lk')->create();
        NotificationPreference::factory()->mailDisabled($invitee, NotificationType::TeamWelcome)->create();
        $token = $this->aPendingInvitation($company, $actor, 'anushka@member.lk');

        // When — they accept
        $response = $this->acceptInvitation($token, [], $invitee);

        // Then — the join succeeds; the welcome mail is suppressed while the
        // in-app notification survives
        $response->assertOk();
        $this->assertTeamWelcomeSuppressed('anushka@member.lk', $company);
    }

    #[Test]
    public function when_an_authenticated_existing_user_accepts_then_they_join_the_new_company(): void
    {
        // Given — an account already on the platform, invited to a company it
        // doesn't belong to; the invitation email differs only in case (alt-4c)
        [$company, $actor] = $this->aCompanyWithMember();
        $invitee = User::factory()->withEmail('anushka@member.lk')->create();
        $token = $this->aPendingInvitation($company, $actor, 'Anushka@Member.LK');
        $accountsBefore = User::query()->count();

        // When — they accept while authenticated; no registration details needed
        $response = $this->acceptInvitation($token, [], $invitee);

        // Then — the membership is activated on the invitation's own UUID with
        // the invited role, no second account appears, and the welcome goes out
        $response->assertOk();
        $response->assertJsonPath('data.user.id', $invitee->getKey());
        $this->assertMembershipActivated($company, 'Anushka@Member.LK');
        $this->assertSame($accountsBefore, User::query()->count());
        $this->assertTeamWelcomeQueued('anushka@member.lk', $company);
    }

    #[Test]
    public function when_an_existing_user_accepts_then_their_membership_is_returned_in_full(): void
    {
        // Given — an account already on the platform, invited to a company it
        // doesn't belong to
        [$company, $actor] = $this->aCompanyWithMember();
        $invitee = User::factory()->withEmail('anushka@member.lk')->create();
        $token = $this->aPendingInvitation($company, $actor, 'anushka@member.lk');

        // When — they accept while authenticated
        $response = $this->acceptInvitation($token, [], $invitee);

        // Then — the 200 body carries the full roster-shaped membership,
        // keyed by the (company, invitee) canonical pivot (findById's scope)
        $this->assertMembershipReturned($response, $invitee, 'employee');
        $response->assertJsonPath('data.id', CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $invitee->getKey())
            ->sole()
            ->getKey());
    }

    /**
     * The accept 200 carries the roster-shaped membership in the {data}
     * envelope: 200 status, exact 6-key arity, member identity, role,
     * active status, and the null timestamp pair (no writers this phase).
     */
    protected function assertMembershipReturned(TestResponse $response, User $member, string $roleCode): void
    {
        $response->assertOk();
        $response->assertJsonCount(6, 'data');
        $response->assertJson([
            'data' => [
                'user' => [
                    'id' => $member->getKey(),
                    'name' => $member->name,
                    'email' => $member->email,
                ],
                'role' => [
                    'code' => $roleCode,
                    'name' => $this->seededRole($roleCode)->name,
                ],
                'status' => 'active',
                'invited_at' => null,
                'last_active_at' => null,
            ],
        ]);
    }

    #[Test]
    public function when_a_removed_member_accepts_a_reinvite_then_the_same_membership_is_restored(): void
    {
        // Given — a member removed from the company (soft-deleted pivot),
        // re-invited onto the SAME membership stream (D5b)
        [$company, $actor] = $this->aCompanyWithMember();
        $removed = User::factory()->withEmail('ruwan@member.lk')->create();
        $membershipId = $this->aRemovedMembership($company, $removed);
        $token = $this->aPendingInvitation($company, $actor, 'ruwan@member.lk');

        // When — they accept the re-invite while authenticated
        $response = $this->acceptInvitation($token, [], $removed);

        // Then — the SAME pivot UUID comes back live (undelete-in-place),
        // not a second row
        $response->assertOk();
        $response->assertJsonPath('data.id', $membershipId);
        $this->assertMembershipActivated($company, 'ruwan@member.lk');
    }

    #[Test]
    public function when_an_active_member_accepts_a_second_pending_invitation_then_no_duplicate_membership_is_created(): void
    {
        // Given — an active member, plus a leftover pending invitation for their
        // email: the OQ-1-126 concurrent-invite race artifact, seeded through the
        // guardless aggregate exactly as the race would produce it
        [$company, $actor] = $this->aCompanyWithMember();
        $member = User::factory()->withEmail('ruwan@member.lk')->create();
        $this->attachMember($company, $member, $this->seededRole('employee'));
        $originalMembershipId = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $member->getKey())
            ->sole()
            ->getKey();
        $plaintext = Str::random(40);
        UserMembership::retrieve((string) Str::uuid7())
            ->invite(CreateInvitationCommand::from([
                'companyId' => $company->getKey(),
                'email' => 'ruwan@member.lk',
                'roleId' => $this->seededRole('employee')->getKey(),
                'invitedByUserId' => $actor->getKey(),
                'plaintextToken' => $plaintext,
            ]))
            ->persist();

        // When — they accept the leftover invitation while authenticated
        $response = $this->acceptInvitation($plaintext, [], $member);

        // Then — idempotent (UC alt-4a): the invitation is consumed but exactly
        // ONE membership row exists, and it is their ORIGINAL membership (the
        // leftover invitation's UUID never becomes a row)
        $response->assertOk();
        $response->assertJsonPath('data.id', $originalMembershipId);
        $this->assertNotNull(Invitation::query()->where('email', 'ruwan@member.lk')->sole()->accepted_at);
        $this->assertSame(1, CompanyUser::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('user_id', $member->getKey())
            ->count());
        $this->assertDatabaseHas('company_user', ['id' => $originalMembershipId]);
    }

    #[Test]
    public function when_a_new_user_accepts_then_their_account_is_created_and_they_are_logged_in(): void
    {
        // Given — a pending invitation for a brand-new email
        [$company, $actor] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $actor, 'kasun@newjoiner.lk');

        // When — the invitee sets their name + password on the accept link
        $response = $this->acceptInvitation($token, $this->validNewUserBody());

        // Then — the account is provisioned (event-sourced genesis), the
        // membership activates on the invitation's UUID, a working session is
        // issued (auto-login), and the welcome goes out
        $response->assertCreated();
        $this->assertNewUserProvisioned('kasun@newjoiner.lk', 'Nimal Perera');
        $this->assertMembershipActivated($company, 'kasun@newjoiner.lk');
        $this->assertAutoLoggedIn($response, $company);
        $this->assertTeamWelcomeQueued('kasun@newjoiner.lk', $company);
    }

    #[Test]
    public function when_a_new_user_accepts_then_their_chosen_password_becomes_their_login_credential(): void
    {
        // Given — an invitation accepted with a chosen password
        [$company, $actor] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $actor, 'kasun@newjoiner.lk');
        $this->acceptInvitation($token, $this->validNewUserBody())->assertCreated();
        Auth::forgetGuards();

        // When — they log in with that password
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'kasun@newjoiner.lk',
            'password' => $this->validNewUserBody()['password'],
        ]);

        // Then — a fresh session is issued against the accept-time credential
        $response->assertOk();
        $this->assertNotSame('', (string) $response->json('data.token'), 'The accept-time password must log in.');
    }

    #[Test]
    public function when_the_accept_reads_the_invitation_it_will_consume_then_it_locks_it(): void
    {
        // Given — an existing account invited to a company it doesn't belong to
        [$company, $actor] = $this->aCompanyWithMember();
        $invitee = User::factory()->withEmail('anushka@member.lk')->create();
        $token = $this->aPendingInvitation($company, $actor, 'anushka@member.lk');
        $invitationId = (string) Invitation::query()->where('email', 'anushka@member.lk')->sole()->getKey();

        // When — the accept runs with every SQL statement recorded
        $capture = $this->recordedQueriesDuring(
            fn () => $this->acceptInvitation($token, [], $invitee)->assertOk()
        );

        // Then — the consume decided under held locks, in one transaction
        $this->assertAcceptDecidedUnderInvitationLock($capture, $invitationId);
        $this->assertPivotSetLockedBeforeWrite($capture, (string) $company->getKey(), (string) $invitee->getKey());
    }

    #[Test]
    public function when_a_member_removed_from_another_company_accepts_then_they_join_this_one(): void
    {
        // Given — an invitee removed from a DIFFERENT company (their trashed
        // pivot lives there), another removed member in THIS company, and a
        // valid invitation from this company
        [$otherCompany] = $this->aCompanyWithMember();
        [$company, $actor] = $this->aCompanyWithMember();
        $invitee = User::factory()->withEmail('ruwan@member.lk')->create();
        $this->aRemovedMembership($otherCompany, $invitee);
        $this->aRemovedMembership($company, User::factory()->withEmail('sunil@member.lk')->create());
        $token = $this->aPendingInvitation($company, $actor, 'ruwan@member.lk');

        // When — they accept while authenticated
        $response = $this->acceptInvitation($token, [], $invitee);

        // Then — a removal elsewhere (or someone else's removal here) does
        // not block this join
        $response->assertOk();
        $this->assertMembershipActivated($company, 'ruwan@member.lk');
    }

    #[Test]
    public function when_a_new_user_accepts_then_the_provisioning_decides_under_the_invitation_lock(): void
    {
        // Given — a pending invitation for a brand-new email
        [$company, $actor] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $actor, 'kasun@newjoiner.lk');
        $invitationId = (string) Invitation::query()->where('email', 'kasun@newjoiner.lk')->sole()->getKey();

        // When — the accept runs with every SQL statement recorded
        $capture = $this->recordedQueriesDuring(
            fn () => $this->acceptInvitation($token, $this->validNewUserBody())->assertCreated()
        );

        // Then — the genesis + join decided under the invitation lock, in
        // one transaction
        $this->assertAcceptDecidedUnderInvitationLock($capture, $invitationId);
    }

    #[Test]
    public function when_a_provisioned_user_stream_is_asked_to_provision_again_then_it_refuses(): void
    {
        // Given — a user stream already born from an invitation genesis
        // (unreachable over HTTP — the interactor mints a fresh uuid per accept —
        // so the re-fire guard is proven at the aggregate seam, mirroring the
        // Company::create re-create guard precedent)
        $genesis = CreateUserFromInvitationCommand::from([
            'companyId' => (string) Str::uuid7(),
            'email' => 'kasun@newjoiner.lk',
            'passwordHash' => 'a-bcrypt-hash-stand-in',
            'name' => 'Nimal Perera',
        ]);
        $userId = (string) Str::uuid7();
        UserAggregate::retrieve($userId)->createFromInvitation($genesis)->persist();

        // When / Then — a second genesis on the SAME stream is refused
        $this->expectException(UserAlreadyRegisteredException::class);
        UserAggregate::retrieve($userId)->createFromInvitation($genesis);
    }
}
