<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
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
 * holds the domain object and each subclass supplies getTitle() / getBody() /
 * getMailable(), and LeaveWasRejectedReactor passes the entity in directly. LD
 * has no generic system-notification class at all.
 *
 * LD's base ALSO carries the acting user, and this one deliberately does not
 * (yet): no title or body in this module names an actor, so carrying one would
 * have been dead state on a base four more transitions inherit — and it cost a
 * User lookup in each of the three reactors to populate something nothing read
 * (cut at the s152 review, the same call PR #114 made on the unconsumed
 * company() relation). The first transition whose copy genuinely names "rejected
 * by X" adds it back RED-first, where a test can hold it.
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
    ) {}

    /**
     * The tenant the notice concerns. Read through the transfer's relation rather
     * than passed in, so no subclass can render a notice about one company while
     * naming another.
     *
     * The null-safe cast yields '' if the relation were ever absent, which reads
     * as a silent failure and was raised as such at the s152 review. Left as-is
     * deliberately: company_id is NOT NULL behind a RESTRICT FK, so the branch is
     * unreachable, and a guard for it would be untestable production code that
     * then fails the 100%-patch-coverage gate — exactly the uncovered-unreachable
     * line codecov rejected at s149. Declared rather than built, per that
     * precedent. Revisit if Company ever gains SoftDeletes, which is the change
     * that would make it reachable.
     */
    protected function companyName(): string
    {
        return (string) $this->transfer->company?->name;
    }

    abstract protected function title(): string;

    abstract protected function body(): string;

    /**
     * The same two channels SystemNotification uses — but UNCONDITIONALLY, and
     * that is a behaviour CHANGE to two already-merged UCs, not a greenfield
     * choice (recorded properly at s153; the earlier wording here read as new
     * code declining to add preferences).
     *
     * `SystemNotification::via()` omits the mail channel for a recipient who has
     * opted out of a non-critical type, and `GeneralAccountNotice` is
     * non-critical — so the T1 and T2 reactors retrofitted onto this base at H3
     * LOST a gate they previously passed through. Nothing regressed in practice,
     * verified rather than assumed: `NotificationPreference` has exactly one
     * reader (that `via()`) and no writer anywhere outside a factory, so no user
     * can hold a preference row today.
     *
     * The forward problem is the real one: these notifications carry no
     * `NotificationType`, so when the preference UC lands there is no key any
     * user could set to suppress ownership-transfer mail — un-suppressible by
     * construction rather than by decision. Also the one place this module parts
     * company with LeaveDates on a pattern LD has (Rule #15): LD's
     * `RequestNotification::via()` delegates to
     * `$notifiable->getNotificationChannels()`, gating only `mail` while
     * database/broadcast/push always land.
     *
     * Deliberately still not fixed here — preference handling belongs in ONE
     * place for every type (OQ-1-104's funnel question), not copied per module.
     * Carried there with this note.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', QueuedMailChannel::class];
    }

    public function toMail(object $notifiable): OwnershipTransferNotificationMail
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
