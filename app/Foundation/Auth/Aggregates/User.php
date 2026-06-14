<?php

namespace App\Foundation\Auth\Aggregates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use App\Foundation\EventStore\AggregateRoot;
use App\Foundation\Auth\Events\UserEmailVerified;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\Events\UserMembershipEmergencyContactSet;
use App\Foundation\Auth\Exceptions\UserAlreadyRegisteredException;

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

    public function createOwner(string $companyId, RegisterOwnerCommand $command): static
    {
        if ($this->registered) {
            throw UserAlreadyRegisteredException::withId($this->uuid());
        }

        return $this->forCompany($companyId)
            ->recordThat(new UserSignedUpAsOwner(
                userId: $this->uuid(),
                companyId: $companyId,
                email: $command->email,
                name: $command->name,
                passwordHash: Hash::make($command->password),
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

    protected function applyUserSignedUpAsOwner(UserSignedUpAsOwner $event): void
    {
        $this->registered = true;
    }

    protected function applyUserEmailVerified(UserEmailVerified $event): void
    {
        $this->verified = true;
    }
}
