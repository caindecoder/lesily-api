<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use Throwable;
use Carbon\CarbonInterface;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Events\OwnershipTransferExpired;
use App\Companies\OwnershipTransfers\UseCases\Commands\ExpireOwnershipTransferCommand;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;
use App\Companies\OwnershipTransfers\Aggregates\OwnershipTransferRequest as OwnershipTransferRequestAggregate;

/**
 * The daily batch that expires every transfer past its SLA window (SM-1-05
 * T8/T9, System actor). The boundary hands it the cutoff; it reads the due rows
 * from the repository and ends each one through the aggregate. Delivery stays a
 * thin appendix (E07).
 *
 * Named for the rows it selects, matching the two built scans
 * (`ExpireElapsedTrialsInteractor`, `ExpirePendingInvitationsInteractor`) — the
 * qualifier is the point (ruling M5).
 *
 * No `DB::transaction` per row, unlike `ExpirePendingInvitationsInteractor`
 * (ruling M4). That precedent wraps to stop a committed event without its
 * projection marker being re-expired on the next scan — a hazard that exists
 * only because ITS aggregate is guardless. Ours is not: `expireIfStale()`
 * replays the stream and no-ops once the state has left the open set, so a
 * second event cannot be recorded however often the row is re-selected. A
 * projector failure can still leave one row divergent (stream `Expired`,
 * projection not) — caught PER ROW below (F-b, Task R1), reported, and the
 * batch continues; the divergent row replays as `Expired`, so a re-selection
 * no-ops. The module-wide synchronous-reactor posture is Task R2's question
 * (OQ-1-153); E-f landed at Task 8.
 */
class ExpireStaleOwnershipTransfersInteractor
{
    public function __construct(
        private OwnershipTransferRequestRepositoryInterface $transfers,
    ) {}

    public function execute(CarbonInterface $asOf): int
    {
        $expired = 0;

        foreach ($this->transfers->listTimerReady($asOf) as $requestId => $status) {
            try {
                // §S10b re-read (F-a / OQ-1-156): selection excluded held companies,
                // but a hold lands mid-scan without moving transfer status, so the
                // O1 guard cannot see it. Asked again at write time; a held (or
                // vanished) row is skipped, uncounted — the SELECT-time skip-only
                // semantics (D3), applied at the moment that actually writes.
                if (! $this->transfers->clearOfLegalHold($requestId)) {
                    continue;
                }

                $transfer = OwnershipTransferRequestAggregate::retrieve($requestId)
                    ->expireIfStale(ExpireOwnershipTransferCommand::validateAndCreate([
                        'expectedStatus' => $status,
                        'expiredAfterDays' => $this->windowFor($status),
                        'expiredAt' => $asOf,
                    ]));

                // Rows SELECTED and rows EXPIRED are no longer the same number: since
                // O1 the aggregate can decline a row whose selection went stale, and
                // counting the iteration would report an expiry that never happened.
                // The count is the command's only output and the thing an ops alert
                // would key on, so it has to mean what it says. Both built scans return
                // count($rows) instead, correctly — neither of their aggregates can
                // decline. Asked before persist(), which takes the recorded events away.
                //
                // A declined row is skipped rather than persisted: persisting an empty
                // event set still runs a version query and can throw, so it would add a
                // failure surface for a row that did nothing.
                if ($this->recordedAnExpiry($transfer) === false) {
                    continue;
                }

                $transfer->persist();
                $expired++;
            } catch (Throwable $e) {
                // F-b (UC-1-116/117 Main Flow step 9): one row's failure must not
                // abort the batch. Report and move on — a thrown row was never
                // counted (the increment is the body's last act), and a row that
                // failed AFTER its event became durable replays as Expired, so
                // the next scan no-ops it rather than double-firing (LD idiom:
                // SendCompanyDeletionWarningEmailsAction's per-row catch).
                report($e);

                continue;
            }
        }

        return $expired;
    }

    /**
     * Whether the aggregate actually recorded an expiry for this row.
     *
     * Narrowed to the event class rather than asking "did it record anything":
     * the counter's claim is about EXPIRIES, and a future second `recordThat` in
     * `expireIfStale()` would otherwise keep it green while meaning something
     * different.
     */
    private function recordedAnExpiry(OwnershipTransferRequestAggregate $transfer): bool
    {
        foreach ($transfer->getRecordedEvents() as $event) {
            if ($event instanceof OwnershipTransferExpired) {
                return true;
            }
        }

        return false;
    }

    /**
     * The SLA window the row broke, chosen by its PRE-transition state — the
     * policy number, not measured elapsed time (ruling M2). Config-driven so the
     * windows stay tunable without a deploy (OQ-1-006 locks the defaults).
     */
    private function windowFor(OwnershipTransferStatus $status): int
    {
        // `default` IS the verified arm, and O2 is what makes that safe to say:
        // the repository now selects `requested` or `verified` and nothing else,
        // so no third status can reach here and quietly collect the 14-day figure.
        //
        // Naming both arms and dropping the catch-all was tried and reverted —
        // Larastan L8 requires a match over this parameter to handle all six enum
        // cases, and the honest alternative (`default => throw`) is a line no test
        // can reach, which codecov/patch would reject. The guarantee belongs in the
        // query, where it is now stated, rather than in an unreachable guard here.
        return (int) match ($status) {
            OwnershipTransferStatus::Requested => config('ownership-transfers.request_ttl_days', 30),
            default => config('ownership-transfers.verified_ttl_days', 14),
        };
    }
}
