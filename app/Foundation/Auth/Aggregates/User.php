<?php

namespace App\Foundation\Auth\Aggregates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use App\Foundation\EventStore\AggregateRoot;
use App\Foundation\Auth\Events\User2FAEnabled;
use App\Foundation\Auth\Events\User2FADisabled;
use App\Foundation\Auth\Events\UserEmailVerified;
use App\Foundation\Auth\Events\UserPasswordReset;
use App\Foundation\Auth\Events\UserProfileUpdated;
use App\Foundation\Auth\Events\UserPasswordChanged;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use App\Foundation\Auth\Events\User2FARecoveryCodeUsed;
use App\Foundation\Auth\Events\UserCreatedFromInvitation;
use App\Foundation\Auth\Events\User2FARecoveryCodesRegenerated;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\Events\UserMembershipEmergencyContactSet;
use App\Foundation\Auth\Exceptions\UserAlreadyRegisteredException;
use App\Foundation\Auth\UseCases\Commands\UpdateUserProfileCommand;
use App\Foundation\Auth\UseCases\Commands\CreateUserFromInvitationCommand;

/**
 * The sole identity stream for a users row (slice C1). Owner signup is the genesis;
 * the owner pivot lands with CompanyCreated (FK chain — see CompanyProjector) while
 * membership attributes (emergency contact) ride this stream. UserMembership
 * (invite lifecycle) never creates users.
 */
class User extends AggregateRoot
{
    protected bool $registered = false;

    protected bool $verified = false;

    protected bool $twoFactorEnabled = false;

    public function createOwner(string $companyId, RegisterOwnerCommand $command): static
    {
        if ($this->registered) {
            throw UserAlreadyRegisteredException::withId($this->uuid());
        }

        return $this->forCompany($companyId)
            ->recordThat(new UserSignedUpAsOwner(
                userId: $this->uuid(),
                companyId: $companyId,
                companyName: $command->companyName,
                email: $command->email,
                name: $command->name,
                passwordHash: Hash::make($command->password),
            ));
    }

    /**
     * The SECOND genesis (H1 session 109): an invitee with no account joins via
     * the accept link (UC-1-019). The command carries the already-hashed
     * password (the interactor hashes — slice §5); tenant-stamped with the
     * inviting company so the PDPA event-store walk can attribute why this
     * account exists.
     */
    public function createFromInvitation(CreateUserFromInvitationCommand $command): static
    {
        if ($this->registered) {
            throw UserAlreadyRegisteredException::withId($this->uuid());
        }

        return $this->forCompany($command->companyId)
            ->recordThat(new UserCreatedFromInvitation(
                userId: $this->uuid(),
                email: $command->email,
                name: $command->name,
                passwordHash: $command->passwordHash,
            ));
    }

    public function setEmergencyContact(string $companyId, RegisterOwnerCommand $command): static
    {
        return $this->recordThat(new UserMembershipEmergencyContactSet(
            userId: $this->uuid(),
            companyId: $companyId,
            name: $command->emergencyContactName,
            email: $command->emergencyContactEmail,
            phone: $command->emergencyContactPhone,
            relationship: $command->emergencyContactRelationship,
        ));
    }

    public function verifyEmail(): static
    {
        if ($this->verified) {
            return $this;
        }

        return $this->recordThat(new UserEmailVerified(
            userId: $this->uuid(),
            verifiedAt: CarbonImmutable::now(),
        ));
    }

    public function confirmTwoFactor(): static
    {
        if ($this->twoFactorEnabled) {
            return $this;
        }

        return $this->recordThat(new User2FAEnabled(
            userId: $this->uuid(),
            confirmedAt: CarbonImmutable::now(),
        ));
    }

    public function useRecoveryCode(int $remaining): static
    {
        return $this->recordThat(new User2FARecoveryCodeUsed(
            userId: $this->uuid(),
            remaining: $remaining,
        ));
    }

    /**
     * Guardless (mirrors useRecoveryCode, not the guarded confirmTwoFactor): the disable
     * interactor's read-model not-enabled check is the gate, and the happy path drives the
     * aggregate from a factory-arranged user with no replayed User2FAEnabled — a guard would
     * swallow the marker. applyUser2FADisabled resets the flag so a later re-enable records.
     */
    public function disableTwoFactor(): static
    {
        return $this->recordThat(new User2FADisabled(
            userId: $this->uuid(),
            disabledAt: CarbonImmutable::now(),
        ));
    }

    /**
     * Guardless with no apply (mirrors useRecoveryCode): rotating the recovery codes does
     * not change the enrollment state — the new hashes are written to the users column by
     * the interactor and this is a pure non-secret audit marker.
     */
    public function regenerateRecoveryCodes(): static
    {
        return $this->recordThat(new User2FARecoveryCodesRegenerated(
            userId: $this->uuid(),
            regeneratedAt: CarbonImmutable::now(),
        ));
    }

    /**
     * Guardless with no apply: the password is not aggregate state (no invariant on it) — it is a
     * projected read-model column. The interactor hashes the new password and passes the hash, so the
     * event carries the hash, never plaintext (as at register — though register hashes inside the
     * aggregate, this hashes in the interactor); the projector applies it to users.password. Event-sourced
     * precisely because that column is projected — a direct write would be reverted on replay.
     */
    public function changePassword(string $passwordHash): static
    {
        return $this->recordThat(new UserPasswordChanged(
            userId: $this->uuid(),
            passwordHash: $passwordHash,
            changedAt: CarbonImmutable::now(),
        ));
    }

    /**
     * Guardless with no apply (mirrors changePassword): the password is not aggregate state — it is a
     * projected read-model column. The interactor (inside the broker's reset callback) hashes the new
     * password and passes the hash, so the event carries the hash, never plaintext; the projector applies
     * it to users.password. Event-sourced precisely because that column is projected — a direct write
     * would be reverted on replay.
     */
    public function resetPassword(string $passwordHash): static
    {
        return $this->recordThat(new UserPasswordReset(
            userId: $this->uuid(),
            passwordHash: $passwordHash,
            resetAt: CarbonImmutable::now(),
        ));
    }

    /**
     * Guardless with no apply (mirrors changePassword): name and locale are not aggregate
     * state (no invariant on them) — they are projected read-model columns. The interactor
     * owns the no-op comparison against the read model (the stream cannot know the current
     * locale: genesis events carry none — the initial 'en' is only a column default), so an
     * identical submit never reaches this method. Event-sourced precisely because those
     * columns are projected — a direct write would be reverted on replay.
     *
     * @param  list<string>  $changedFields
     */
    public function updateProfile(UpdateUserProfileCommand $command, array $changedFields): static
    {
        return $this->recordThat(new UserProfileUpdated(
            userId: $this->uuid(),
            name: $command->name,
            locale: $command->locale,
            changedFields: $changedFields,
            updatedAt: CarbonImmutable::now(),
        ));
    }

    protected function applyUserSignedUpAsOwner(UserSignedUpAsOwner $event): void
    {
        $this->registered = true;
    }

    protected function applyUserCreatedFromInvitation(UserCreatedFromInvitation $event): void
    {
        $this->registered = true;
    }

    protected function applyUserEmailVerified(UserEmailVerified $event): void
    {
        $this->verified = true;
    }

    protected function applyUser2FAEnabled(User2FAEnabled $event): void
    {
        $this->twoFactorEnabled = true;
    }

    protected function applyUser2FADisabled(User2FADisabled $event): void
    {
        $this->twoFactorEnabled = false;
    }
}
