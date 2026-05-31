<?php

namespace App\Foundation\Notifications\Channels;

use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Translation\HasLocalePreference;
use App\Foundation\Notifications\Notifications\SystemNotification;

/**
 * Notification channel that queues the notification's Mailable instead of sending
 * it inline (Laravel's default mail channel sends synchronously). Queuing lets the
 * email retry on its own queued job — independently of the in-app (database) record,
 * which the database channel has already written — so an email failure never blocks
 * or duplicates the in-app notification.
 */
class QueuedMailChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var SystemNotification $notification */
        $mailable = $notification->toMail($notifiable);

        if ($notifiable instanceof HasLocalePreference && ($locale = $notifiable->preferredLocale()) !== null) {
            $mailable->locale($locale);
        }

        Mail::to($notifiable)->queue($mailable);
    }
}
