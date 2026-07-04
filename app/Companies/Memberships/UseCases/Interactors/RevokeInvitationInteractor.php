<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Exceptions\InvitationNotPendingException;

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
    public function execute(Company $company, User $actor, string $invitationId): void
    {
        Invitation::query()
            ->where('company_id', $company->getKey())
            ->whereKey($invitationId)
            ->firstOrFail();

        throw new InvitationNotPendingException;
    }
}
