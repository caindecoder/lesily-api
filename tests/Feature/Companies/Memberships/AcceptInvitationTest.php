<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Responses\ApiErrorCode;

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
}
