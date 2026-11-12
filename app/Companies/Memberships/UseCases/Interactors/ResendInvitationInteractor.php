<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Mail\InvitationMail;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Companies\Memberships\Services\InvitationAllowance;
use App\Companies\Memberships\UseCases\Audit\InvitationAudit;
use App\Companies\Memberships\Exceptions\InvitationNotPendingException;
use App\Companies\Memberships\UseCases\Commands\ResendInvitationCommand;
use App\Companies\Memberships\Exceptions\InvitationAllowanceExceededException;
use App\Companies\Memberships\UseCases\Commands\ResendPendingInvitationCommand;

/**
 * Resend a pending invitation (UC-1-021): guard live-pending (R1 s110 — the
 * pending definition is accepted_at NULL + not-revoked + unexpired; the revoked
 * term joined at Task 5, R6), mint a fresh bearer token, record
 * UserInvitationResent (tokenHash
 * only — D3), and re-dispatch the accept-link email INLINE on the SAME
 * invitations row (single live token; InviteUserInteractor precedent, R8).
 */
class ResendInvitationInteractor
{
    public function __construct(
        private InvitationAllowance $allowance,
        private InvitationAudit $audit,
    ) {}

    /**
     * @throws InvitationAllowanceExceededException
     */
    public function execute(ResendPendingInvitationCommand $command, User $actor, RequestOrigin $origin): Invitation
    {
        $this->allowance->spend($actor, 1);

        $company = $command->company;
        $invitation = Invitation::query()
            ->where('company_id', $company->getKey())
            ->whereKey($command->invitationId)
            ->firstOrFail();

        $isLivePending = $invitation->accepted_at === null
            && $invitation->revoked_at === null
            && $invitation->expires_at->isFuture();

        if (! $isLivePending) {
            throw new InvitationNotPendingException;
        }

        $plaintextToken = Str::random(40);

        DB::transaction(function () use ($invitation, $plaintextToken, $actor, $origin): void {
            UserMembership::retrieve($invitation->getKey())
                ->resendInvitation(ResendInvitationCommand::from([
                    'plaintextToken' => $plaintextToken,
                    'resentBy' => $actor,
                ]))
                ->persist();

            $this->audit->resent($invitation, $actor, $origin);
        });

        $invitation->refresh()->load('role');

        Mail::to($invitation->email)->queue(new InvitationMail(
            companyName: $company->name,
            email: $invitation->email,
            plaintextToken: $plaintextToken,
            expiresAt: $invitation->expires_at,
        ));

        return $invitation;
    }
}
