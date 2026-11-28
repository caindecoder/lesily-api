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
        public NotificationType $notificationType,
        public string $companyName,
        public ?string $companyId,
        public array $details = [],
    ) {}

    /**
     * @param  User  $recipient
     * @return array<int, string>
     */
    public function via(object $recipient): array
    {
        $channels = [CompanyDatabaseChannel::class];

        if ($this->notificationType->isCritical() || ! $this->mailOptedOut($recipient)) {
            $channels[] = QueuedMailChannel::class;
        }

        return $channels;
    }

    /**
     * @param  User  $recipient
     */
    public function toMail(object $recipient): SystemNotificationMail
    {
        return (new SystemNotificationMail($this->notificationType, $this->companyName, $this->details))
            ->locale($recipient->preferredLocale());
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    public function toArray(object $recipient): array
    {
        return [
            'notification_type' => $this->notificationType->value,
            'company_name' => $this->companyName,
            'details' => $this->details,
        ];
    }

    /**
     * @param  User  $recipient
     */
    protected function mailOptedOut(object $recipient): bool
    {
        return NotificationPreference::query()
            ->where('user_id', $recipient->getKey())
            ->where('notification_type', $this->notificationType->value)
            ->where('channel', self::MAIL_CHANNEL)
            ->where('enabled', false)
            ->exists();
    }
}
