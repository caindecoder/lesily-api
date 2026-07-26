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
 *
 * The `SendsQueuedMail` narrowing is in the SIGNATURE, not a docblock (s153).
 * Laravel resolves channels by duck typing — there is no interface to satisfy —
 * so narrowing the parameter is legal and costs no caller: `ChannelManager`
 * passes positionally. It matters because the previous `@var` was an assertion
 * rather than a check: a future notification listing this channel in `via()`
 * without implementing the contract passed static analysis silently and died at
 * runtime with "Call to undefined method toMail()", mid-dispatch, after the
 * in-app record had already committed. That is the same failure the interface was
 * extracted to prevent — the extraction replaced a false docblock with an
 * unenforced one.
 */
class QueuedMailChannel
{
    public function send(object $notifiable, Notification&SendsQueuedMail $notification): void
    {
        /** @var User $notifiable */
        Mail::to($notifiable)->queue($notification->toMail($notifiable));
    }
}
