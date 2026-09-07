<?php

namespace App\Foundation\Auth\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 * Queued (ShouldQueue): neither password flow wraps a transaction, so the stored event and the
 * projected hash are already durable when this runs — an inline send that failed could not roll
 * anything back, but it 500ed the request and skipped the session revocation (and, on reset, the
 * token consumption) that follow persist(). Off the queue a delivery failure lands in failed_jobs
 * and retries instead. The companyName is resolved deterministically by
 * User::notificationCompanyName() (OQ-1-124) — a company-less user degrades to an empty label
 * rather than failing the notice.
 */
class PasswordChangedNotificationReactor extends Reactor implements ShouldQueue
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
            $user->notificationCompanyName(),
        ));
    }
}
