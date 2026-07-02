<?php

namespace App\Companies\Memberships\Aggregates;

use Carbon\CarbonImmutable;
use App\Foundation\EventStore\AggregateRoot;
use App\Companies\Memberships\Events\UserInvited;
use App\Companies\Memberships\UseCases\Commands\CreateInvitationCommand;

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
}
