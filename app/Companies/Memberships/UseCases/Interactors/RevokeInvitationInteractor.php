<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Companies\Entities\Company;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Companies\Memberships\Exceptions\InvitationNotPendingException;
use App\Companies\Memberships\UseCases\Commands\RevokeInvitationCommand;

/**
 * Revoke a pending invitation (UC-1-022) — the token is dead thereafter.
 *
 * Company-scoped lookup: an unknown id and another company's id are equally
 * 404 (existence is never leaked across tenants). Only a live pending
 * invitation may be revoked — accepted/revoked/expired all collapse to the
 * 422 INVITE_NOT_PENDING guard (R-B s111).
 */
class RevokeInvitationInteractor
{
    public function execute(Company $company, RevokeInvitationCommand $command, string $invitationId): void
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

        UserMembership::retrieve($invitation->getKey())
            ->revokeInvitation($command)
            ->persist();
    }
}
