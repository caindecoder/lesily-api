<?php

namespace App\Companies\OwnershipTransfers\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Foundation\Http\Values\PlatformAdminAllowlist;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferCancelled;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferRequested;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferNotification;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferRequestedNotification;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferWithdrawnNotification;

/**
 * Alerts the platform support team about the two ends of a transfer's life on
 * the support queue: it ARRIVED for verification (SM-1-05 T1) and it was
 * WITHDRAWN by its own requester (T6/T7, both windows, ruling J4). Recipients
 * resolve from the interim `platform.admin_user_ids` allowlist (s136 binding —
 * the platform-admin slice replaces it with real admin auth); the notifications
 * are OwnershipTransferRequestedNotification and
 * OwnershipTransferWithdrawnNotification, not cd-notifications' bespoke
 * dispatcher (CD-1-05's Slack/Teams note is unbuilt infra).
 *
 * Queued (ShouldQueue) — ruling Q1 (s164, Task R2): a NEW decision, not CD
 * conformance. This reactor was never among the CD's six `Queued.` rows, but
 * leaving it synchronous would have kept the OQ-1-153 failure class (a
 * throwing notification 5xx-ing a committed write) alive on T1/T6/T7 alone.
 * Delivery-time staleness is benign here, for a reason worth stating: the T1
 * arm may deliver after the row has already moved on (an immediate
 * withdrawal), but this same reactor announces the departure too (J4), so the
 * pair self-corrects — and the notification bodies are per-class static text
 * + tenant name, not status renderings. Contrast PlatformAdminQueueReactor,
 * whose alert asserts a live claim ("awaiting execution") and therefore
 * carries the Q2 delivery-time status guard.
 *
 * This docblock has now gone stale twice in the commit that made it stale. It
 * said "the native SystemNotification per OQ-1-104" until s152, when the H3
 * retrofit replaced the body and left it asserting the opposite; then it still
 * scoped the class to T1 alone after the cancel arm landed. Both were caught by
 * review rather than by any gate — the second time with this very paragraph
 * sitting underneath, describing the first.
 */
class SupportQueueNotificationReactor extends Reactor implements ShouldQueue
{
    public function onOwnershipTransferRequested(OwnershipTransferRequested $event): void
    {
        $this->alertSupport(
            $event->requestId,
            fn (OwnershipTransferRequest $transfer) => new OwnershipTransferRequestedNotification($transfer),
        );
    }

    /**
     * SM-1-05 T6 + T7 (ruling J4). Deliberately UNFILTERED by `source`: this
     * reactor announced the request's arrival, so it announces its departure in
     * both windows. Contrast `VerifierNotificationReactor`, which IS
     * source-filtered because only one window has a verifier to tell.
     *
     * Both windows are asserted. Filtering this to `pre-verification` used to
     * leave the whole suite green, which is how the T7 row came to exist.
     */
    public function onOwnershipTransferCancelled(OwnershipTransferCancelled $event): void
    {
        $this->alertSupport(
            $event->requestId,
            fn (OwnershipTransferRequest $transfer) => new OwnershipTransferWithdrawnNotification($transfer),
        );
    }

    /**
     * Recipients resolve from the interim `platform.admin_user_ids` allowlist
     * (s136 binding — the platform-admin slice replaces it with real admin auth).
     *
     * Takes a FACTORY rather than a class-string. The class-string form was
     * satisfiable by a subclass this method cannot actually construct:
     * `OwnershipTransferRejectedNotification` is a valid
     * class-string<OwnershipTransferNotification> but takes a second `$reason`
     * argument, so passing it type-checked, passed Larastan, and threw
     * ArgumentCountError at runtime. The two-literal shape this extraction
     * replaced could not express that mistake; a closure restores that property
     * and keeps the deduplication.
     *
     * @param  callable(OwnershipTransferRequest): OwnershipTransferNotification  $notification
     */
    private function alertSupport(string $requestId, callable $notification): void
    {
        $transfer = OwnershipTransferRequest::query()->findOrFail($requestId);

        User::query()
            ->whereIn('id', PlatformAdminAllowlist::userIds())
            ->get()
            ->each(fn (User $admin) => $admin->notify($notification($transfer)));
    }
}
