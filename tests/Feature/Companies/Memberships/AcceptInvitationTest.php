<?php

namespace Tests\Feature\Companies\Memberships;

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
}
