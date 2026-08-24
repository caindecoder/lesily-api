<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\DeadlockException;
use App\Foundation\Database\LockContention;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use App\Foundation\Auth\UseCases\SessionIssuer;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Companies\Memberships\UseCases\Results\NewUserJoined;
use App\Companies\Memberships\UseCases\Results\ExistingUserJoined;
use App\Companies\Memberships\Exceptions\InvalidInvitationException;
use App\Companies\Memberships\Exceptions\InviteEmailMismatchException;
use App\Companies\Memberships\UseCases\Commands\AcceptInvitationCommand;
use App\Foundation\Auth\UseCases\Commands\CreateUserFromInvitationCommand;
use App\Companies\Memberships\Exceptions\InvitationAcceptContentionException;

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
    public function __construct(
        private readonly SessionIssuer $sessionIssuer,
    ) {}

    public function execute(AcceptInvitationCommand $command, ?User $authUser, string $deviceName): NewUserJoined|ExistingUserJoined
    {
        $invitation = $this->livePendingInvitation($command->token);
        $invitee = User::query()->where('email', $invitation->email)->first();

        // A held session must belong to the invited email (UC alt-4b; matched
        // case-insensitively per alt-4c) — whoever else is logged in, 403.
        // The DB email lookups above rely on MySQL's CI collation for the same
        // property; the platform-wide normalization policy is OQ-1-127 (open).
        if ($authUser instanceof User && Str::lower($authUser->email) !== Str::lower($invitation->email)) {
            throw new InviteEmailMismatchException;
        }

        if ($invitee instanceof User && ! $authUser instanceof User) {
            throw new AuthenticationException;
        }

        if (! $invitee instanceof User) {
            $this->ensureRegistrationDetails($command);

            return $this->provisionAndJoin($invitation, $command, $deviceName);
        }

        return new ExistingUserJoined($this->join($invitation, $invitee));
    }

    /**
     * Classify lock contention from THIS accept's transaction only — the
     * post-commit tail (read-model reads, last_logged_at, session mint) is
     * deliberately non-atomic and must never answer "retry": the membership
     * is already durable, so a retry would find the link consumed.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $transaction
     * @return TReturn
     */
    protected function classifyingContention(callable $transaction): mixed
    {
        try {
            return $transaction();
        } catch (DeadlockException $exception) {
            throw new InvitationAcceptContentionException($exception);
        } catch (QueryException $exception) {
            throw LockContention::caused($exception)
                ? new InvitationAcceptContentionException($exception)
                : $exception;
        }
    }

    /**
     * The new-email main flow: genesis + join in ONE transaction (UC minimal
     * guarantee), then — mirroring RegisterOwnerInteractor — materialise the
     * read model, stamp last_logged_at, and mint the session OUTSIDE the
     * transaction (a failed mint leaves a provisioned member who can log in).
     */
    protected function provisionAndJoin(Invitation $invitation, AcceptInvitationCommand $command, string $deviceName): NewUserJoined
    {
        $userId = (string) Str::uuid7();
        // Hashed BEFORE the transaction: bcrypt takes ~300ms at production
        // rounds, and the invitation row lock must not be held through it.
        $passwordHash = Hash::make((string) $command->password);

        $this->classifyingContention(fn () => DB::transaction(function () use ($invitation, $command, $userId, $passwordHash): void {
            $live = $this->lockedLivePendingInvitation($invitation);

            UserAggregate::retrieve($userId)
                ->createFromInvitation(CreateUserFromInvitationCommand::validateAndCreate([
                    'companyId' => $live->company_id,
                    'email' => $live->email,
                    'passwordHash' => $passwordHash,
                    'name' => (string) $command->name,
                ]))
                ->persist();

            UserMembership::retrieve($live->getKey())
                ->acceptInvitation($userId, $live->role_id)
                ->persist();
        }));

        $user = User::query()->findOrFail($userId);
        $user->last_logged_at = now();
        $user->save();

        return new NewUserJoined($user, $this->sessionIssuer->issue($user, $deviceName));
    }

    /**
     * SM-1-01 T4 — the one atomic unit (UC-1-019 minimal guarantee): the
     * aggregate records UserJoinedCompany and its projection stamps
     * invitations.accepted_at + writes the pivot together, or none of it lands.
     */
    protected function join(Invitation $invitation, User $joiner): CompanyUser
    {
        $this->classifyingContention(fn () => DB::transaction(function () use ($invitation, $joiner): void {
            $live = $this->lockedLivePendingInvitation($invitation);
            $this->guardAgainstResurrectingARemovedMember($live, $joiner);

            UserMembership::retrieve($live->getKey())
                ->acceptInvitation($joiner->getKey(), $live->role_id)
                ->persist();
        }));

        // The live pivot for (company, joiner) — normally the invitation's own
        // UUID (D5b); on an idempotent alt-4a accept, the earlier membership
        // the projector kept canonical.
        return CompanyUser::query()
            ->where('company_id', $invitation->company_id)
            ->where('user_id', $joiner->getKey())
            ->sole();
    }

    /**
     * The invitation re-read under a held row lock (must run inside the accept
     * transaction, after which the lock serializes concurrent consumers): the
     * live-pending conditions re-checked here decide against committed state,
     * so a concurrent accept's loser collapses to INVITE_INVALID instead of a
     * duplicate stream append. The token_hash clause covers the one live-state
     * mutation the other predicates miss — a resend rotating the token.
     */
    protected function lockedLivePendingInvitation(Invitation $invitation): Invitation
    {
        $live = Invitation::query()
            ->whereKey($invitation->getKey())
            ->where('token_hash', $invitation->token_hash)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();

        if (! $live instanceof Invitation) {
            throw new InvalidInvitationException;
        }

        return $live;
    }

    /**
     * A leftover invitation (a different stream than the member's own pivot)
     * must not re-admit a REMOVED member: their soft-deleted pivot still owns
     * the (company, user) unique slot — at most ONE row exists here — and
     * re-entry is the deliberate same-UUID re-invite (D5b), never a stale
     * token, so the accept collapses to INVITE_INVALID (H4). A live sibling
     * passes untouched (alt-4a). The locked read serializes the decision
     * against the membership writers that lock the same pivot row (the
     * OQ-1-158 convention); lock order is invitations → company_user.
     */
    protected function guardAgainstResurrectingARemovedMember(Invitation $lockedInvitation, User $joiner): void
    {
        $sibling = CompanyUser::withTrashed()
            ->where('company_id', $lockedInvitation->company_id)
            ->where('user_id', $joiner->getKey())
            ->lockForUpdate()
            ->first();

        if ($sibling instanceof CompanyUser
            && $sibling->deleted_at !== null
            && $sibling->getKey() !== $lockedInvitation->getKey()) {
            throw new InvalidInvitationException;
        }
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
     * plaintext (only the hash is at rest — D3/R10), require not-yet-accepted,
     * not-revoked (H2, joined Task 5) and not-yet-expired. Anything else is
     * indistinguishably invalid.
     */
    protected function livePendingInvitation(string $plaintextToken): Invitation
    {
        $invitation = Invitation::query()
            ->where('token_hash', hash('sha256', $plaintextToken))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $invitation instanceof Invitation) {
            throw new InvalidInvitationException;
        }

        return $invitation;
    }
}
