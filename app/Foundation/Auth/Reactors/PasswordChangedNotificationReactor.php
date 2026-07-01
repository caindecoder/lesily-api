<?php

namespace App\Foundation\Auth\Reactors;

use App\Models\User;
use App\Foundation\Auth\Events\UserPasswordChanged;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;

/**
 * Confirms a password change to its owner (UC-1-016). Fires on the stored UserPasswordChanged
 * event, so the confirmation is bound to a COMMITTED change and never a failed attempt, and sends
 * the critical PasswordChanged SystemNotification whose isCritical() tier bypasses opt-out — an
 * account-security notice cannot be silenced (questionnaire S16 L474). Synchronous, like
 * TrialExpiredEmailReactor: the change-password flow has no wrapping transaction, so there is no
 * rollback to defer the send past (the email leg still queues inside SystemNotification). The
 * companyName is denormalised from the acting user's membership; a company-less user — which never
 * occurs in production, where every user is a company owner — degrades to an empty label rather
 * than failing the notice.
 */
class PasswordChangedNotificationReactor extends Reactor
{
    public function onUserPasswordChanged(UserPasswordChanged $event): void
    {
        $user = User::query()->findOrFail($event->userId);

        $user->notify(new SystemNotification(
            NotificationType::PasswordChanged,
            (string) $user->companies()->value('name'),
        ));
    }
}
