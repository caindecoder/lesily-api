<?php

namespace App\Foundation\Auth\Reactors;

use App\Models\User;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Sends the "verify your email" link when an owner signs up (UC-1-001 alt-6a,
 * Pattern D side effect). Listens to UserSignedUpAsOwner — the slice's genesis
 * event — rather than cd-auth's UserSignedUp, which belongs to the later
 * non-owner signup path. Reuses Laravel's native VerifyEmail notification, which
 * builds the signed `verification.verify` URL; nothing is gated on verified
 * status this slice (no enforcement).
 */
class EmailVerificationReactor extends Reactor
{
    public function onUserSignedUpAsOwner(UserSignedUpAsOwner $event): void
    {
        User::query()->findOrFail($event->userId)->sendEmailVerificationNotification();
    }
}
