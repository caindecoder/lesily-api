<?php

namespace App\Foundation\Auth\Aggregates;

use Illuminate\Support\Facades\Hash;
use App\Foundation\EventStore\AggregateRoot;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\Exceptions\UserAlreadyRegisteredException;

/**
 * The sole identity stream for a users row (slice C1). Owner signup is the genesis;
 * the membership pivot is projected from UserSignedUpAsOwner. UserMembership (invite
 * lifecycle) never creates users.
 */
class User extends AggregateRoot
{
    protected bool $registered = false;

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

    protected function applyUserSignedUpAsOwner(UserSignedUpAsOwner $event): void
    {
        $this->registered = true;
    }
}
