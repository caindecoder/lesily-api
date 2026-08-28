<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use App\Companies\Entities\CompanyUser;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Mail\InvitationMail;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Companies\Memberships\Exceptions\AlreadyMemberException;
use App\Companies\Memberships\UseCases\Commands\InviteUserCommand;
use App\Companies\Memberships\UseCases\Commands\CreateInvitationCommand;

/**
 * Invite a user to the company (UC-1-018): guard, mint the bearer token, record
 * UserInvited (tokenHash only — D3), and dispatch the accept-link email INLINE —
 * a reactor only sees the event, which deliberately lacks the plaintext
 * (ForgotPasswordInteractor precedent; R8).
 */
class InviteUserInteractor
{
    public function execute(Company $company, User $actor, InviteUserCommand $command): Invitation
    {
        $userId = User::query()->where('email', $command->email)->value('id');

        // A soft-deleted (removed) membership is NOT a member — its re-invite is
        // the D5b undelete-in-place path below, not an ALREADY_MEMBER rejection.
        if ($userId !== null && $company->users()->whereKey($userId)->wherePivotNull('deleted_at')->exists()) {
            throw new AlreadyMemberException;
        }

        // One live invitation per (company, email): a standing pending invite is
        // rejected the same way — rotating its token is Resend's job (UC-1-021).
        // (Live = unaccepted + not-revoked + unexpired; a revoked invitation is
        // terminal, so its email re-invites into a fresh row — R6 s111.)
        $hasLivePendingInvitation = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $command->email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($hasLivePendingInvitation) {
            throw AlreadyMemberException::pendingInvitation();
        }

        // A removed member's re-invite resumes their membership stream (stable
        // UUID = the soft-deleted pivot's id — D5b); a fresh invite opens a new one.
        $membershipId = $this->removedMembershipId($company, $userId) ?? Str::uuid7()->toString();
        $plaintextToken = Str::random(40);

        UserMembership::retrieve($membershipId)
            ->invite(CreateInvitationCommand::from([
                'companyId' => $company->getKey(),
                'email' => $command->email,
                'roleId' => Role::query()->where('code', $command->roleCode)->sole()->getKey(),
                'invitedByUserId' => $actor->getKey(),
                'plaintextToken' => $plaintextToken,
            ]))
            ->persist();

        $invitation = Invitation::query()->with('role')->findOrFail($membershipId);

        Mail::to($command->email)->queue(new InvitationMail(
            companyName: $company->name,
            email: $command->email,
            plaintextToken: $plaintextToken,
            expiresAt: $invitation->expires_at,
        ));

        return $invitation;
    }

    private function removedMembershipId(Company $company, ?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        /** @var ?string $membershipId the pivot is UUID-keyed (HasUuids) */
        $membershipId = CompanyUser::onlyTrashed()
            ->where('company_id', $company->getKey())
            ->where('user_id', $userId)
            ->value('id');

        return $membershipId;
    }
}
