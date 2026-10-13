<?php

namespace App\Foundation\Notifications\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Channels\QueuedMailChannel;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
use App\Foundation\Notifications\Channels\CompanyDatabaseChannel;
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

    /** The notification_preferences.channel value for the email leg. */
    public const MAIL_CHANNEL = 'mail';

    /**
     * @param  array<string, string>  $details  values the type's body names beside the company
     */
    public function __construct(
        public NotificationType $type,
        public string $companyName,
        public ?string $companyId,
        public array $details = [],
    ) {}

    /**
     * @param  User  $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [CompanyDatabaseChannel::class];

        if ($this->type->isCritical() || ! $this->mailOptedOut($notifiable)) {
            $channels[] = QueuedMailChannel::class;
        }

        return $channels;
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): SystemNotificationMail
    {
        return (new SystemNotificationMail($this->type, $this->companyName, $this->details))
            ->locale($notifiable->preferredLocale());
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => $this->type->value,
            'company_name' => $this->companyName,
            'details' => $this->details,
        ];
    }

    /**
     * @param  User  $notifiable
     */
    private function mailOptedOut(object $notifiable): bool
    {
        return NotificationPreference::query()
            ->where('user_id', $notifiable->getKey())
            ->where('notification_type', $this->type->value)
            ->where('channel', self::MAIL_CHANNEL)
            ->where('enabled', false)
            ->exists();
    }
}
