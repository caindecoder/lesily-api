<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use App\Foundation\Notifications\Contracts\SendsQueuedMail;
use App\Foundation\Notifications\Channels\QueuedMailChannel;
use App\Companies\OwnershipTransfers\IO\Mails\OwnershipTransferNotificationMail;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest as TransferReadModel;

/**
 * Base for every ownership-transfer lifecycle notification (s151 ruling H2).
 * Each transition contributes a thin subclass supplying its own title and body;
 * everything structural lives here.
 *
 * This exists because the Foundation SystemNotification takes only
 * (NotificationType, companyName) and so cannot say WHICH transfer moved or why —
 * T1 and T2 emitted byte-identical notices for two different events, which is the
 * defect OQ-1-144 recorded. A new enum case could not have fixed it: the shape
 * had nowhere to carry a transfer id.
 *
 * The shape is LeaveDates' (Rule #15 — LD primary): its LeaveNotification base
 * holds the domain object plus the actor and each subclass supplies getTitle() /
 * getBody() / getMailable(), and LeaveWasRejectedReactor passes the entity in
 * directly. LD has no generic system-notification class at all.
 *
 * Runs synchronously so the in-app (database) record lands immediately; the email
 * leg is a queued Mailable retrying independently, so an email failure never
 * blocks or duplicates the in-app record (the SystemNotification contract).
 */
abstract class OwnershipTransferNotification extends Notification implements SendsQueuedMail
{
    use Queueable;

    public function __construct(
        public TransferReadModel $transfer,
        public User $actor,
    ) {}

    /**
     * The tenant the notice concerns. Read through the transfer's relation rather
     * than passed in, so no subclass can render a notice about one company while
     * naming another.
     */
    protected function companyName(): string
    {
        return (string) $this->transfer->company?->name;
    }

    abstract protected function title(): string;

    abstract protected function body(): string;

    /**
     * Lifecycle notices are never opt-out-able the way a marketing notice is, but
     * they are not security-critical either, so they follow the same two channels
     * SystemNotification uses. Preference handling is deliberately NOT duplicated
     * here — when a transfer-notification preference is actually demanded it
     * belongs in one place for every notification type (OQ-1-104's funnel
     * question), not copied per module.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', QueuedMailChannel::class];
    }

    public function toMail(object $notifiable): Mailable
    {
        /** @var User $notifiable */
        return (new OwnershipTransferNotificationMail($this->title(), $this->body()))
            ->locale($notifiable->preferredLocale());
    }

    /**
     * The in-app record. Carries the transfer id so the client can deep-link to
     * the transfer the notice is about — the thing the generic notification could
     * not express.
     *
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ownership_transfer_request_id' => $this->transfer->getKey(),
            'title' => $this->title(),
            'body' => $this->body(),
        ];
    }
}
