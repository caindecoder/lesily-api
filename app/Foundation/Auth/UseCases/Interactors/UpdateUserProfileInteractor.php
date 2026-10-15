<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\UseCases\Commands\UpdateUserProfileCommand;

/**
 * Update own profile (UC-1-015): event-source the new name + notification locale onto
 * the built User aggregate and return the refreshed read model. Owns the no-op guard
 * (slice-profile B5) — an identical submit records NO event, because the audit trail
 * records applied changes only. The comparison runs here against the read model, not
 * inside the aggregate: the stream cannot know the current locale (genesis events
 * carry none — the initial 'en' is only a column default). No side effects — unlike a
 * password change there is nothing to revoke and no one to notify.
 */
class UpdateUserProfileInteractor
{
    public function execute(User $user, UpdateUserProfileCommand $command): User
    {
        $changedFields = $this->changedFields($user, $command);

        if ($changedFields === []) {
            return $user;
        }

        DB::transaction(fn () => UserAggregate::retrieve($user->getKey())
            ->updateProfile($command, $changedFields)
            ->persist());

        return $user->refresh();
    }

    /**
     * @return list<string>
     */
    protected function changedFields(User $user, UpdateUserProfileCommand $command): array
    {
        return array_keys(array_filter([
            'name' => $user->name !== $command->name,
            'locale' => $user->locale !== $command->locale,
        ]));
    }
}
