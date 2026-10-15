<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Mail\InvitationMail;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Companies\Memberships\Exceptions\InvitationNotPendingException;
use App\Companies\Memberships\UseCases\Commands\ResendInvitationCommand;

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
    public function execute(Company $company, User $actor, string $invitationId): Invitation
    {
        $invitation = Invitation::query()
            ->where('company_id', $company->getKey())
            ->whereKey($invitationId)
            ->firstOrFail();

        $isLivePending = $invitation->accepted_at === null
            && $invitation->revoked_at === null
            && $invitation->expires_at->isFuture();

        if (! $isLivePending) {
            throw new InvitationNotPendingException;
        }

        $plaintextToken = Str::random(40);

        DB::transaction(fn () => UserMembership::retrieve($invitation->getKey())
            ->resendInvitation(ResendInvitationCommand::from([
                'plaintextToken' => $plaintextToken,
                'resentBy' => $actor->getKey(),
            ]))
            ->persist());

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
