<?php

namespace App\Foundation\Notifications\Channels;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\Notification;
use App\Foundation\Notifications\Contracts\SendsQueuedMail;

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
        /** @var SendsQueuedMail $notification */
        /** @var User $notifiable */
        Mail::to($notifiable)->queue($notification->toMail($notifiable));
    }
}
