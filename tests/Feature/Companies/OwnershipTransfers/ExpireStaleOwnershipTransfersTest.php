<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use Carbon\CarbonImmutable;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\OwnershipTransfers\Enums\TransitionSource;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExpired;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

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

        // Then it is expired at the requested stage
        $this->assertTransferExpiredAtStage($transfer, TransitionSource::RequestedStage, 30);
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
        $this->assertTransferExpiredAtStage($transfer, TransitionSource::VerifiedStage, 14);
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
     * $transfer reached the Expired terminal at $stage: the projection carries
     * the stage and the elapsed-window figure, the SLA timer is cleared, and a
     * stored OwnershipTransferExpired event backs it.
     *
     * The stored-event assertion is amendment R: `expired_at`/`expiry_source`
     * are projected columns, so a direct read-model write would satisfy every
     * other assertion here while leaving the aggregate silent — and be reverted
     * on the next projection replay.
     */
    protected function assertTransferExpiredAtStage(
        OwnershipTransferRequest $transfer,
        TransitionSource $stage,
        int $afterDays,
    ): void {
        $current = $this->currentTransfer($transfer);

        $this->assertSame([
            'status' => OwnershipTransferStatus::Expired,
            'expiry_source' => $stage->value,
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
