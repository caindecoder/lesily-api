<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\Exceptions\UserAlreadyRegisteredException;
use App\Companies\Memberships\UseCases\Commands\CreateInvitationCommand;
use App\Foundation\Auth\UseCases\Commands\CreateUserFromInvitationCommand;

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
        $this->assertRefusedWith($response, ApiErrorCode::InviteEmailMismatch);
        $this->assertInvitationNotConsumed($company, 'anushka@member.lk');
    }

    /**
     * @param  array<string, string>  $body
     */
    #[Test, DataProvider('invalidNewUserDetailsProvider')]
    public function when_a_new_users_registration_details_are_invalid_then_no_account_is_created(array $body, string $field): void
    {
        // Given — a pending invitation for a brand-new email
        [$company, $actor] = $this->aCompanyWithMember();
        $token = $this->aPendingInvitation($company, $actor, 'kasun@newjoiner.lk');

        // When — accepting with a broken registration body
        $response = $this->acceptInvitation($token, $body);

        // Then — shape-rejected on the offending field; nothing was created
        $this->assertValidationRejected($response, $field);
        $this->assertInvitationNotConsumed($company, 'kasun@newjoiner.lk');
        $this->assertDatabaseMissing('users', ['email' => 'kasun@newjoiner.lk']);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidNewUserDetailsProvider(): array
    {
        return [
            'password missing' => [['name' => 'Nimal Perera'], 'password'],
            'password empty' => [['name' => 'Nimal Perera', 'password' => ''], 'password'],
            'password below the 8-char minimum' => [['name' => 'Nimal Perera', 'password' => 'Short7!'], 'password'],
            'name missing' => [['password' => 'Str0ng-Pass!'], 'name'],
            'name whitespace only' => [['name' => '   ', 'password' => 'Str0ng-Pass!'], 'name'],
            'name beyond the 255-char column' => [['name' => str_repeat('a', 256), 'password' => 'Str0ng-Pass!'], 'name'],
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
    public function when_a_provisioned_user_stream_is_asked_to_provision_again_then_it_refuses(): void
    {
        // Given — a user stream already born from an invitation genesis
        // (unreachable over HTTP — the interactor mints a fresh uuid per accept —
        // so the re-fire guard is proven at the aggregate seam, mirroring the
        // Company::create re-create guard precedent)
        $genesis = CreateUserFromInvitationCommand::validateAndCreate([
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
