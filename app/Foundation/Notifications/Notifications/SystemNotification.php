<?php

namespace App\Foundation\Notifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Channels\QueuedMailChannel;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
use App\Foundation\Notifications\Entities\NotificationPreference;

/**
 * Foundation system notification: delivers an email and an in-app record for a
 * system event affecting a user.
 *
 * Runs synchronously so the in-app (database) channel is written immediately;
 * the email leg is a queued Mailable (SystemNotificationMail) that retries
 * independently, so email failure never blocks the in-app record.
 */
class SystemNotification extends Notification
{
    use Queueable;

    public function __construct(
        public NotificationType $type,
        public string $companyName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($this->type->isCritical() || ! $this->mailOptedOut($notifiable)) {
            $channels[] = QueuedMailChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): SystemNotificationMail
    {
        return new SystemNotificationMail($this->type, $this->companyName);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => $this->type->value,
            'company_name' => $this->companyName,
        ];
    }

    private function mailOptedOut(object $notifiable): bool
    {
        return NotificationPreference::query()
            ->where('user_id', $notifiable->getKey())
            ->where('notification_type', $this->type->value)
            ->where('channel', 'mail')
            ->where('enabled', false)
            ->exists();
    }
}
