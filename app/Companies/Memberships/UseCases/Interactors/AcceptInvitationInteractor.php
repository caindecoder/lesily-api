<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\CompanyUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Companies\Memberships\UseCases\Results\ExistingUserJoined;
use App\Companies\Memberships\Exceptions\InvalidInvitationException;
use App\Companies\Memberships\Exceptions\InviteEmailMismatchException;
use App\Companies\Memberships\UseCases\Commands\AcceptInvitationCommand;

/**
 * Accept a team invitation (UC-1-019). The emailed plaintext token is hashed
 * and matched against the LIVE pending invitation (accepted_at NULL + unexpired
 * — the revoked guard joins with the Task-5 column); every token failure
 * collapses to one INVITE_INVALID (H4). An invitee whose email already has an
 * account must be authenticated first (R9/D7 — the security-over-friction
 * divergence from LeaveDates' token-sufficient accept).
 */
class AcceptInvitationInteractor
{
    public function execute(AcceptInvitationCommand $command, ?User $authUser): ExistingUserJoined
    {
        $invitation = $this->livePendingInvitation($command->token);
        $invitee = User::query()->where('email', $invitation->email)->first();

        // A held session must belong to the invited email (UC alt-4b; matched
        // case-insensitively per alt-4c) — whoever else is logged in, 403.
        if ($authUser instanceof User && Str::lower($authUser->email) !== Str::lower($invitation->email)) {
            throw new InviteEmailMismatchException;
        }

        if ($invitee instanceof User && ! $authUser instanceof User) {
            throw new AuthenticationException;
        }

        if (! $invitee instanceof User) {
            $this->ensureRegistrationDetails($command);

            throw new RuntimeException('Not implemented');
        }

        return new ExistingUserJoined($this->join($invitation, $invitee));
    }

    /**
     * SM-1-01 T4 — the one atomic unit (UC-1-019 minimal guarantee): the
     * aggregate records UserJoinedCompany and its projection stamps
     * invitations.accepted_at + writes the pivot together, or none of it lands.
     */
    protected function join(Invitation $invitation, User $joiner): CompanyUser
    {
        DB::transaction(function () use ($invitation, $joiner): void {
            UserMembership::retrieve($invitation->getKey())
                ->acceptInvitation($joiner->getKey(), $invitation->role_id)
                ->persist();
        });

        return CompanyUser::query()
            ->whereKey($invitation->getKey())
            ->with(['user', 'role'])
            ->sole();
    }

    /**
     * The new-email branch creates an account, so name + password become
     * required HERE (their presence depends on invitee existence, which the
     * shape rules cannot know — AcceptInvitationCommand::rules covers shape).
     */
    protected function ensureRegistrationDetails(AcceptInvitationCommand $command): void
    {
        $errors = [];

        if (! is_string($command->password) || $command->password === '') {
            $errors['password'] = 'A password is required to create your account.';
        }

        if (! is_string($command->name) || trim($command->name) === '') {
            $errors['name'] = 'Your name is required to create your account.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The invitation this token resolves to, if it is still live: sha256 the
     * plaintext (only the hash is at rest — D3/R10), require not-yet-accepted
     * and not-yet-expired. Anything else is indistinguishably invalid.
     */
    protected function livePendingInvitation(string $plaintextToken): Invitation
    {
        $invitation = Invitation::query()
            ->where('token_hash', hash('sha256', $plaintextToken))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $invitation instanceof Invitation) {
            throw new InvalidInvitationException;
        }

        return $invitation;
    }
}
