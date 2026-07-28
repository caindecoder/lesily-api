<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use Carbon\CarbonImmutable;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
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
