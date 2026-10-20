<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use Throwable;
use RuntimeException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Compliance\Dsar\Events\DsarSlaBreached;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\UseCases\Audit\DsarOutcomeAudit;

class FlagMissedDsarDeadlinesInteractor
{
    public function __construct(private readonly DsarOutcomeAudit $audit) {}

    /**
     * Flags every open request whose deadline passed by $at, each request in
     * its own transaction, and returns how many it flagged. A request that
     * fails is reported and left for the next run; the others are still
     * flagged.
     */
    public function execute(CarbonInterface $at): int
    {
        /** @var array<int, string> $requestIds */
        $requestIds = DsarRequestRecord::query()->whereBreachDueToFlag($at)->pluck('id')->all();
        $flagged = 0;

        foreach ($requestIds as $requestId) {
            try {
                if ($this->flag($requestId, $at)) {
                    $flagged++;
                }
            } catch (Throwable $failure) {
                report(new RuntimeException("DSAR deadline flag failed for request {$requestId}.", previous: $failure));
            }
        }

        return $flagged;
    }

    /**
     * Whether the request's aggregate recorded the breach — one a concurrent
     * run already recorded is neither audited nor counted again.
     */
    protected function flag(string $requestId, CarbonInterface $at): bool
    {
        return DB::transaction(function () use ($requestId, $at): bool {
            $request = DsarRequest::retrieve($requestId)->flagSlaBreach($at);

            if ($request->getRecordedEvents() === []) {
                return false;
            }

            $request->persist();
            $this->audit->record(DsarRequestRecord::query()->findOrFail($requestId), DsarSlaBreached::class, AuditAction::DsarDeadlineMissed);

            return true;
        });
    }
}
