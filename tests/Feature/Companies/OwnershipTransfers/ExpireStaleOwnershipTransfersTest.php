<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExpired;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferExpiredNotification;
use App\Companies\OwnershipTransfers\IO\Notifications\OwnershipTransferVerificationLapsedNotification;

/**
 * SM-1-05 T8 + T9 — the daily `ownership-transfers:expire-stale` scan ends
 * transfers that outlived their SLA window (UC-1-116 requested-stage 30d,
 * UC-1-117 verified-stage 14d). The slice's first SYSTEM flow: no HTTP surface,
 * no role gate, no tenant context — the actor is the scheduler.
 *
 * EVERY row here arranges a REAL stream and travels past the deadline. The scan
 * reads two different sources — selection queries the projection, the transition
 * replays the stream — so a factory row satisfies the first and is EMPTY for the
 * second. Under ruling M1 the aggregate would then see a null status, take the
 * no-op arm and record nothing: the scan reports "0 expired" and a careless test
 * passes for entirely the wrong reason. That is s149 C1 with a silent failure
 * mode instead of a throwing one.
 */
class ExpireStaleOwnershipTransfersTest extends OwnershipTransfersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));
    }

    // Sad — a transfer still inside its 30-day window is not due, so the daily
    // scan selects nothing and the request stays open (the `<` cutoff excludes it).
    #[Test]
    public function when_no_transfer_has_passed_its_deadline_then_the_scan_expires_nothing(): void
    {
        // Given a requested transfer still within its window
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'));

        // When the daily scan runs while the window is still open
        $this->runExpiryScan()->expectsOutputToContain('0 transfer(s) expired.')->assertSuccessful();

        // Then the transfer is untouched
        $this->assertTransferStillOpen($transfer);
    }

    // Happy (UC-1-116 sc.1, T8) — a requested transfer that outlived its 30-day
    // window is ended by the scan: the aggregate records the expiry at the
    // requested stage, the projection goes terminal and the SLA timer clears.
    #[Test]
    public function when_a_requested_transfer_outlives_its_thirty_day_window_then_it_expires_at_the_requested_stage(): void
    {
        // Given a requested transfer whose 30-day window has elapsed
        $transfer = $this->anOverdueRequestedTransfer();

        // When the daily scan runs
        $this->runExpiryScan()->expectsOutputToContain('1 transfer(s) expired.')->assertSuccessful();

        // Then it is expired at the requested stage and its requester is told
        $this->assertTransferExpiredAtStage($transfer, 'requested-stage', 30);
        $this->assertRequesterWasNotifiedOfExpiry($transfer);
        $this->assertExpiryAudited($transfer);
    }

    // Happy (UC-1-117 sc.1, T9) — a VERIFIED transfer that outlived its shorter
    // 14-day window expires at the verified stage. The window is shorter because
    // execution is a single admin click, so a stale verification is the risk.
    #[Test]
    public function when_a_verified_transfer_outlives_its_fourteen_day_window_then_it_expires_at_the_verified_stage(): void
    {
        // Given a verified transfer whose 14-day window has elapsed
        $transfer = $this->anOverdueVerifiedTransfer();

        // When the daily scan runs
        $this->runExpiryScan()->expectsOutputToContain('1 transfer(s) expired.')->assertSuccessful();

        // Then it is expired at the verified stage, on the 14-day window
        $this->assertTransferExpiredAtStage($transfer, 'verified-stage', 14);
        $this->assertRequesterWasNotifiedOfExpiry($transfer);
        $this->assertVerifierWasNotifiedOfLapse($transfer);
    }

    // Happy (UC-1-116, the T8 half of the dual-notification asymmetry) — nobody
    // verified a requested-stage transfer, so there is no verifier to tell. The
    // paired negative of the T9 row above: without it the source filter is
    // asserted from one side only, which is precisely how the equivalent filter
    // went unenforced at s155 (91 tests stayed green while it was silenced).
    #[Test]
    public function when_a_transfer_expires_before_it_was_verified_then_no_verifier_is_notified(): void
    {
        // Given a requested transfer whose 30-day window has elapsed, never verified
        $this->anOverdueRequestedTransfer();

        // When the daily scan runs
        $this->runExpiryScan()->assertSuccessful();

        // Then no verification-lapsed notice goes out
        $this->assertNoVerifierWasNotified();
    }

    /**
     * No verification-lapsed notice was sent to anyone.
     *
     * The revert-check for this row is worth stating precisely, because what it
     * proves is stronger than the assertion reads. Removing `VerifierNotificationReactor`'s
     * source filter does not merely send a spurious notice — a requested-stage
     * transfer has a NULL `verified_by`, so the reactor's `findOrFail` throws and
     * the whole scan fails. The filter is load-bearing for the scan's survival,
     * not only for its etiquette, and `assertSuccessful()` in the caller is what
     * catches that half.
     */
    protected function assertNoVerifierWasNotified(): void
    {
        Notification::assertSentTimes(OwnershipTransferVerificationLapsedNotification::class, 0);
    }

    /**
     * A requested transfer whose 30-day window has just elapsed — arranged
     * through a REAL T1 stream, then the clock moved past the deadline the T1
     * projector wrote.
     */
    protected function anOverdueRequestedTransfer(): OwnershipTransferRequest
    {
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aRequestedTransferStream($company, $owner, $this->aMemberWithRole($company, 'employee'));

        $this->travel(31)->days();

        return $transfer;
    }

    /**
     * A verified transfer whose 14-day post-verification window has just
     * elapsed — a REAL T1 → T2 stream, so the deadline under test is the one the
     * T2 projector RESET (verified_at + 14d), not the T1 anchor it replaced.
     */
    protected function anOverdueVerifiedTransfer(): OwnershipTransferRequest
    {
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aVerifiedTransferStream($company, $owner, $this->aPlatformSupportAdmin());

        $this->travel(15)->days();

        return $transfer;
    }

    /**
     * $transfer reached the Expired terminal at $expectedSource: the projection
     * carries the stage and the elapsed-window figure, the SLA timer is cleared,
     * and a stored OwnershipTransferExpired event backs it.
     *
     * `$expectedSource` is a plain STRING, and callers pass literals. Taking a
     * `TransitionSource` and asserting `$stage->value` would read the expected
     * value from the same enum production renders from, so both sides would move
     * together and the assertion could not fail — the s155 L1 defect exactly
     * (respelling `PostVerification` as `post_verification` left 91 tests green).
     * The withdraw and reject classes pin their own `*_source` values as literals
     * for this reason; ERD-1-05 §3 is the canon these literals answer to.
     *
     * The stored-event assertion is amendment R: `expired_at`/`expiry_source`
     * are projected columns, so a direct read-model write would satisfy every
     * other assertion here while leaving the aggregate silent — and be reverted
     * on the next projection replay.
     */
    protected function assertTransferExpiredAtStage(
        OwnershipTransferRequest $transfer,
        string $expectedSource,
        int $afterDays,
    ): void {
        $current = $this->currentTransfer($transfer);

        $this->assertSame([
            'status' => OwnershipTransferStatus::Expired,
            'expiry_source' => $expectedSource,
            'expired_after_days' => $afterDays,
            'next_state_transition_at' => null,
        ], [
            'status' => $current->status,
            'expiry_source' => $current->expiry_source,
            'expired_after_days' => $current->expired_after_days,
            'next_state_transition_at' => $current->next_state_transition_at,
        ]);
        $this->assertNotNull($current->expired_at);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExpired::class)
            ->where('event_properties->requestId', $transfer->getKey())
            ->count());
    }

    /**
     * The transfer's original requester was told it lapsed — exactly once.
     *
     * The times-assertion is the load-bearing half: `assertSentTo` alone stays
     * green if the reactor fires for every recipient it can reach, which is the
     * shape that let T1 and T2 emit byte-identical notices (OQ-1-144). It is
     * scoped to this notification class rather than a global `assertCount`,
     * because the T1 genesis in the arrangement legitimately notifies the
     * support queue first.
     */
    protected function assertRequesterWasNotifiedOfExpiry(OwnershipTransferRequest $transfer): void
    {
        $requester = User::query()->findOrFail($transfer->current_owner_user_id);

        Notification::assertSentTo($requester, OwnershipTransferExpiredNotification::class);
        Notification::assertSentTimes(OwnershipTransferExpiredNotification::class, 1);
    }

    /**
     * The admin who performed the prior T2 verification was told their work
     * lapsed unexecuted — UC-1-117's dual-notification asymmetry, and the whole
     * reason T9 differs from T8.
     *
     * The verifier is resolved from the projection's `verified_by` (ruling M3),
     * written once at T2 by the table's sole writer straight from that event's
     * own payload field. UC-1-117 asks for a `stored_events` read instead; that
     * would be the first production read of the event store anywhere in the app
     * and would couple this reactor to an event class name and payload shape
     * (OQ-1-108) — the coupling J6 kept out of payloads.
     */
    protected function assertVerifierWasNotifiedOfLapse(OwnershipTransferRequest $transfer): void
    {
        $verifier = User::query()->findOrFail($this->currentTransfer($transfer)->verified_by);

        Notification::assertSentTo($verifier, OwnershipTransferVerificationLapsedNotification::class);
        Notification::assertSentTimes(OwnershipTransferVerificationLapsedNotification::class, 1);
    }

    /**
     * The expiry left a chain-of-custody row naming no human actor.
     *
     * Every column here is pinned, and that is deliberate: at s155 the equivalent
     * audit assertion could not fail — `stored_event_id` stripped, the role code
     * changed to `admin` and the description rewritten all left 92 tests green.
     * The actor triple is asserted NULL rather than skipped, because "no human
     * did this" is the row's whole claim (ruling M7) and an unasserted null is
     * indistinguishable from lost attribution.
     *
     * `stored_event_id` is a nullable soft FK, so a dropped value inserts happily
     * — asserting it non-null is what keeps the audit row pointing at the event
     * that justifies it.
     */
    protected function assertExpiryAudited(OwnershipTransferRequest $transfer): void
    {
        $entry = AuditEntry::query()
            ->where('subject_id', $transfer->getKey())
            ->where('event_class', 'OwnershipTransferExpired')
            ->sole();

        $this->assertSame([
            'action' => 'transfer_expired',
            'company_id' => $transfer->company_id,
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
        ], [
            'action' => $entry->action,
            'company_id' => $entry->company_id,
            'actor_user_id' => $entry->actor_user_id,
            'actor_role_code' => $entry->actor_role_code,
            'actor_label' => $entry->actor_label,
        ]);
        $this->assertNotNull($entry->stored_event_id);
        $this->assertSame(
            'The ownership transfer expired before it was completed; the current owner retains ownership.',
            $entry->description,
        );
    }

    protected function runExpiryScan(): PendingCommand
    {
        $command = $this->artisan('ownership-transfers:expire-stale');
        assert($command instanceof PendingCommand);

        return $command;
    }

    /**
     * $transfer was NOT expired: status and SLA deadline unchanged, and no
     * OwnershipTransferExpired event carries its id.
     *
     * The stored-event count is the load-bearing half. An unchanged projection
     * proves nothing about whether the aggregate recorded, and under ruling M1 a
     * mis-arranged fixture fails SILENTLY rather than throwing — so a row that
     * only compared columns would stay green against an empty stream.
     */
    protected function assertTransferStillOpen(OwnershipTransferRequest $transfer): void
    {
        $current = $this->currentTransfer($transfer);

        $this->assertSame($transfer->status, $current->status);
        $this->assertEquals($transfer->next_state_transition_at, $current->next_state_transition_at);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', OwnershipTransferExpired::class)
            ->where('event_properties->requestId', $transfer->getKey())
            ->count());
    }
}
