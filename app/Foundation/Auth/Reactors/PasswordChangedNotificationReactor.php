<?php

namespace App\Foundation\Auth\Reactors;

use App\Models\User;
use App\Foundation\Auth\Events\UserPasswordReset;
use App\Foundation\Auth\Events\UserPasswordChanged;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;

/**
 * Confirms a password change (UC-1-016) OR reset (UC-1-008) to its owner. Fires on the stored
 * UserPasswordChanged / UserPasswordReset event, so the confirmation is bound to a COMMITTED write and
 * never a failed attempt, and sends the critical PasswordChanged SystemNotification whose isCritical()
 * tier bypasses opt-out — an account-security notice cannot be silenced (questionnaire S16 L474).
 * Synchronous, like TrialExpiredEmailReactor: neither flow wraps a transaction, so there is no rollback
 * to defer the send past (the email leg still queues inside SystemNotification). The companyName is
 * denormalised from the acting user's membership; a company-less user — which never occurs in
 * production, where every user is a company owner — degrades to an empty label rather than failing the
 * notice, and a multi-company user resolves to an arbitrary first membership (acceptable while chunk-1
 * users are single-company owners; revisit when employee memberships land).
 */
class PasswordChangedNotificationReactor extends Reactor
{
    public function onUserPasswordChanged(UserPasswordChanged $event): void
    {
        $this->sendConfirmationTo($event->userId);
    }

    public function onUserPasswordReset(UserPasswordReset $event): void
    {
        $this->sendConfirmationTo($event->userId);
    }

    private function sendConfirmationTo(string $userId): void
    {
        $user = User::query()->findOrFail($userId);

        $user->notify(new SystemNotification(
            NotificationType::PasswordChanged,
            (string) $user->companies()->value('name'),
        ));
    }
}
