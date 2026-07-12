<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\UseCases\Commands\UpdateUserProfileCommand;

/**
 * Update own profile (UC-1-015): event-source the new name + notification locale onto
 * the built User aggregate and return the refreshed read model. No side effects —
 * unlike a password change there is nothing to revoke and no one to notify.
 */
class UpdateUserProfileInteractor
{
    public function execute(User $user, UpdateUserProfileCommand $command): User
    {
        UserAggregate::retrieve($user->getKey())
            ->updateProfile($command, ['name', 'locale'])
            ->persist();

        return $user->refresh();
    }
}
