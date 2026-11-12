<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Memberships\Entities\Invitation;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Companies\Memberships\UseCases\Audit\InvitationAudit;
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
    public function __construct(private InvitationAudit $audit) {}

    public function execute(RevokeInvitationCommand $command, RequestOrigin $origin): void
    {
        $invitation = Invitation::query()
            ->where('company_id', $command->company->getKey())
            ->whereKey($command->invitationId)
            ->firstOrFail();

        $isLivePending = $invitation->accepted_at === null
            && $invitation->revoked_at === null
            && $invitation->expires_at->isFuture();

        if (! $isLivePending) {
            throw new InvitationNotPendingException;
        }

        DB::transaction(function () use ($invitation, $command, $origin): void {
            UserMembership::retrieve($invitation->getKey())
                ->revokeInvitation($command)
                ->persist();

            $this->audit->revoked($invitation, $command->revokedBy, $origin);
        });
    }
}
