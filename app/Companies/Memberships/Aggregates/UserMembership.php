<?php

namespace App\Companies\Memberships\Aggregates;

use Carbon\CarbonImmutable;
use App\Foundation\EventStore\AggregateRoot;
use App\Companies\Memberships\Events\UserInvited;
use App\Companies\Memberships\Events\UserJoinedCompany;
use App\Companies\Memberships\Events\UserInvitationResent;
use App\Companies\Memberships\Events\UserInvitationRevoked;
use App\Companies\Memberships\UseCases\Commands\CreateInvitationCommand;
use App\Companies\Memberships\UseCases\Commands\ResendInvitationCommand;
use App\Companies\Memberships\UseCases\Commands\RevokeInvitationCommand;

/**
 * The invited-member lifecycle stream (SM-1-01): one aggregate per membership,
 * its UUID shared with the invitations row and the company_user pivot — a
 * re-invite of a removed member resumes THIS stream, never a fresh one (D5b).
 * The owner pivot never passes through here (CompanyProjector side-effect).
 */
class UserMembership extends AggregateRoot
{
    /**
     * Pending invitations expire 7 days after issue (UC-1-018 main flow step 7).
     */
    private const int INVITATION_TTL_DAYS = 7;

    /**
     * Guardless (mirrors User::changePassword): the interactor's read-model
     * checks (already-member / live-pending-invite) are the gates, and a
     * re-invite legitimately records a second UserInvited on the same stream
     * (D5b) — an aggregate re-fire guard would swallow it.
     */
    public function invite(CreateInvitationCommand $command): static
    {
        return $this->forCompany($command->companyId)
            ->recordThat(new UserInvited(
                invitationId: $this->uuid(),
                companyId: $command->companyId,
                email: $command->email,
                roleId: $command->roleId,
                invitedBy: $command->invitedByUserId,
                expiresAt: CarbonImmutable::now()->addDays(self::INVITATION_TTL_DAYS),
                tokenHash: hash('sha256', $command->plaintextToken),
            ));
    }

    /**
     * SM-1-01 T7 (Invited → Invited): rotate the bearer token + reset the 7-day
     * expiry — guardless like invite() (the interactor's live-pending check is
     * the gate, R1 s110). The command carries the freshly minted plaintext;
     * only its sha256 enters the stream (D3), so the previous link dies with
     * this event (single live token per membership).
     */
    public function resendInvitation(ResendInvitationCommand $command): static
    {
        return $this->recordThat(new UserInvitationResent(
            membershipId: $this->uuid(),
            tokenHash: hash('sha256', $command->plaintextToken),
            expiresAt: CarbonImmutable::now()->addDays(self::INVITATION_TTL_DAYS),
            resentBy: $command->resentBy,
        ));
    }

    /**
     * SM-1-01 T6 (Invited → InvitationRevoked, terminal): an admin cancelled the
     * pending invitation — guardless like invite() (the interactor's live-pending
     * check is the gate, R-B s111). The token dies with the row's revoked_at
     * stamp; nothing is deleted (D5b stable identity survives a later re-invite).
     */
    public function revokeInvitation(RevokeInvitationCommand $command): static
    {
        return $this->recordThat(new UserInvitationRevoked(
            membershipId: $this->uuid(),
            revokedBy: $command->revokedBy,
            revokedAt: CarbonImmutable::now(),
            reason: $command->reason,
        ));
    }

    /**
     * SM-1-01 T4: the invitee consumed their token — guardless like invite()
     * (the interactor's live-pending lookup is the gate; H4 collapses every
     * failure before this method is ever reached). Scalars, not a Command:
     * two fields sits inside the locked 1-2-field tolerance (s071).
     */
    public function acceptInvitation(string $userId, string $roleId): static
    {
        return $this->recordThat(new UserJoinedCompany(
            membershipId: $this->uuid(),
            userId: $userId,
            roleId: $roleId,
            joinedAt: CarbonImmutable::now(),
        ));
    }
}
