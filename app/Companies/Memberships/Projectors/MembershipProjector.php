<?php

namespace App\Companies\Memberships\Projectors;

use App\Companies\Entities\CompanyUser;
use App\Companies\Memberships\Events\UserInvited;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Events\UserJoinedCompany;
use App\Companies\Memberships\Events\UserInvitationResent;
use App\Companies\Memberships\Events\UserInvitationExpired;
use App\Companies\Memberships\Events\UserInvitationRevoked;
use App\Companies\Memberships\Events\UserRemovedFromCompany;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Companies\Memberships\Events\UserMembershipRoleChanged;
use App\Foundation\Auth\Events\UserMembershipEmergencyContactSet;

/**
 * The membership read-model writer, fed by TWO streams: the UserMembership
 * aggregate's full invite lifecycle (invite/accept/resend/revoke → the
 * invitations table; role-change/remove → the company_user pivot; expire →
 * the invitations expired_at marker) and
 * the User identity stream's membership attributes (emergency contact → the
 * company_user pivot, slice C1). The owner pivot itself is written by
 * CompanyProjector (onCompanyCreated; onCompanyOwnershipTransferred since
 * slice-11R) — the FK chain (users → companies → company_user) means the
 * pivot can only land once the company row exists; by the time the EC event
 * persists, the pivot row is in place.
 */
class MembershipProjector extends Projector
{
    /**
     * Upsert, not insert: a re-invite of a removed member reuses the SAME
     * invitation row (id = the resumed aggregate's UUID — D5b), so the fresh
     * token/role/expiry replace the old ones and EVERY terminal marker resets
     * to pending — accept, expiry, and the revoke trio (OQ-1-129). A stale
     * marker would otherwise exclude the genuinely-pending row from the roster
     * and the T5 scan (whereNull filters) and block the invitee's accept. The
     * revocation audit survives in the UserInvitationRevoked stored events;
     * the projection row is disposable.
     */
    public function onUserInvited(UserInvited $event): void
    {
        Invitation::query()->updateOrCreate(
            ['id' => $event->invitationId],
            [
                'company_id' => $event->companyId,
                'role_id' => $event->roleId,
                'email' => $event->email,
                'token_hash' => $event->tokenHash,
                'expires_at' => $event->expiresAt,
                'accepted_at' => null,
                'expired_at' => null,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
                'revocation_reason' => null,
            ],
        );
    }

    /**
     * SM-1-01 T7: rotate the token + expiry IN PLACE on the same invitations
     * row (single-row invariant — LD firstOrCreate parity; the rotation itself
     * is our D3/R10 divergence). accepted_at is untouched: only a live pending
     * row ever receives this event (the interactor gates).
     */
    public function onUserInvitationResent(UserInvitationResent $event): void
    {
        Invitation::query()->whereKey($event->membershipId)->update([
            'token_hash' => $event->tokenHash,
            'expires_at' => $event->expiresAt,
        ]);
    }

    /**
     * SM-1-01 T6: stamp the row revoked IN PLACE — a status column, never a
     * delete (D5b keeps the id shared with any later re-invite upsert). The
     * token dies because every live-pending read excludes revoked rows.
     */
    public function onUserInvitationRevoked(UserInvitationRevoked $event): void
    {
        Invitation::query()->whereKey($event->membershipId)->update([
            'revoked_at' => $event->revokedAt,
            'revoked_by_user_id' => $event->revokedBy,
            'revocation_reason' => $event->reason,
        ]);
    }

    /**
     * SM-1-01 T5: stamp expired_at IN PLACE — a marker column, never a delete
     * (D5b keeps the id shared with any later re-invite upsert). This IS the
     * idempotency gate: once stamped, listExpirablePending's whereNull(expired_at)
     * excludes the row, so a re-run never re-records. Display "Expired" stays
     * derived from expires_at; this column only marks the scan has processed it.
     */
    public function onUserInvitationExpired(UserInvitationExpired $event): void
    {
        Invitation::query()->whereKey($event->membershipId)->update([
            'expired_at' => $event->expiredAt,
        ]);
    }

    /**
     * UC-1-023: the role change lands IN PLACE on the pivot. withTrashed for
     * replay fidelity — a later-removed membership still replays its role
     * history onto the soft-deleted row (onUserJoinedCompany parity).
     */
    public function onUserMembershipRoleChanged(UserMembershipRoleChanged $event): void
    {
        CompanyUser::withTrashed()
            ->whereKey($event->membershipId)
            ->update(['role_id' => $event->toRoleId]);
    }

    /**
     * SM-1-01 T8: soft-delete the pivot IN PLACE (deleted_at stamped) — the row
     * survives (D5b) so a later re-invite undeletes it. withTrashed for replay
     * fidelity (onUserMembershipRoleChanged parity).
     */
    public function onUserRemovedFromCompany(UserRemovedFromCompany $event): void
    {
        CompanyUser::withTrashed()
            ->whereKey($event->membershipId)
            ->update(['deleted_at' => $event->removedAt]);
    }

    /**
     * SM-1-01 T4: stamp the invitation accepted and write the pivot on the SAME
     * UUID (D5b identity — invitation.id == company_user.id == invitation_id;
     * a plain attach() would mint a fresh UUID). A re-invited Removed member's
     * soft-deleted pivot carries this very id, so the write is an
     * undelete-in-place (deleted_at cleared, role refreshed), never a second
     * row. company_id comes off the invitation row this projector wrote at
     * invite time (same transaction).
     */
    public function onUserJoinedCompany(UserJoinedCompany $event): void
    {
        $invitation = Invitation::query()->findOrFail($event->membershipId);
        $invitation->update(['accepted_at' => $event->joinedAt]);

        // Idempotent accept (UC alt-4a): if the member already holds a LIVE
        // pivot from an earlier stream (the OQ-1-126 duplicate-invite race),
        // that membership stays canonical — consuming the leftover invitation
        // must not mint a second row. Replay-safe: same outcome every replay.
        $alreadyMember = CompanyUser::query()
            ->where('company_id', $invitation->company_id)
            ->where('user_id', $event->userId)
            ->whereKeyNot($event->membershipId)
            ->exists();

        if ($alreadyMember) {
            return;
        }

        CompanyUser::withTrashed()->updateOrCreate(
            ['id' => $event->membershipId],
            [
                'company_id' => $invitation->company_id,
                'user_id' => $event->userId,
                'role_id' => $event->roleId,
                'invitation_id' => $event->membershipId,
                'deleted_at' => null,
            ],
        );
    }

    public function onUserMembershipEmergencyContactSet(UserMembershipEmergencyContactSet $event): void
    {
        CompanyUser::query()
            ->where('company_id', $event->companyId)
            ->where('user_id', $event->userId)
            ->update([
                'emergency_contact_name' => $event->name,
                'emergency_contact_email' => $event->email,
                'emergency_contact_phone' => $event->phone,
                'emergency_contact_relationship' => $event->relationship,
            ]);
    }
}
