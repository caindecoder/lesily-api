<?php

namespace App\Foundation\Notifications\Contracts;

use Illuminate\Mail\Mailable;

/**
 * A notification whose email leg is a queued Mailable, delivered by
 * QueuedMailChannel rather than Laravel's synchronous mail channel.
 *
 * Extracted when the channel gained its second consumer: it was written for
 * SystemNotification and narrowed to that class by a docblock, which became a
 * false statement the moment the ownership-transfer notifications started
 * flowing through it. The interface is what the channel actually depends on, so
 * depending on it rather than on a concrete class is the DIP-correct shape (E13)
 * and keeps static analysis honest about who may be passed in.
 */
interface SendsQueuedMail
{
    public function toMail(object $notifiable): Mailable;
}
