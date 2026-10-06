<?php

namespace App\Foundation\Auth\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;

/**
 * Welcomes a new owner once their signup commits. ShouldQueue + after_commit (on
 * the async queue connections) defers the send past the registration transaction,
 * so a mid-transaction rollback discards the email instead of welcoming an account
 * that never existed. The company name rides the event (it is not yet readable from
 * the companies projection when this fires — the owner is persisted first).
 */
class WelcomeEmailReactor extends Reactor implements ShouldQueue
{
    public function onUserSignedUpAsOwner(UserSignedUpAsOwner $event): void
    {
        User::query()->findOrFail($event->userId)
            ->notify(new SystemNotification(NotificationType::GeneralAccountNotice, $event->companyName, $event->companyId));
    }
}
