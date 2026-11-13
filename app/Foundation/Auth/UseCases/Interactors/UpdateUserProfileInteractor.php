<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\AuditTrail\Values\FieldChanges;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\AuditTrail\UseCases\Audit\UserLevelAudit;
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
    public function __construct(protected readonly UserLevelAudit $audit) {}

    public function execute(User $user, UpdateUserProfileCommand $command, RequestOrigin $origin): User
    {
        $changes = $this->changesOf($user, $command);

        if ($changes->isEmpty()) {
            return $user;
        }

        DB::transaction(function () use ($user, $command, $changes, $origin): void {
            UserAggregate::retrieve($user->getKey())
                ->updateProfile($command, $changes->fields())
                ->persist();

            $this->audit->profileUpdated($user->refresh(), $changes, $origin);
        });

        return $user;
    }

    protected function changesOf(User $user, UpdateUserProfileCommand $command): FieldChanges
    {
        return FieldChanges::between(
            ['name' => $user->name, 'locale' => $user->locale],
            ['name' => $command->name, 'locale' => $command->locale->value],
        );
    }
}
