<?php

namespace App\Foundation\Auth\Projectors;

use App\Models\User as UserReadModel;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Mirrors the User identity stream into the `users` read model (ERD-1-01). The
 * event carries the already-hashed password; `locale` keeps its column default.
 */
class UserProjector extends Projector
{
    public function onUserSignedUpAsOwner(UserSignedUpAsOwner $event): void
    {
        UserReadModel::query()->create([
            'id' => $event->userId,
            'name' => $event->name,
            'email' => $event->email,
            'password' => $event->passwordHash,
        ]);
    }
}
