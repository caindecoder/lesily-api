<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Compliance\Dsar\Events\DsarSlaBreached;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\UseCases\Audit\DsarOutcomeAudit;

class FlagMissedDsarDeadlinesInteractor
{
    public function __construct(private readonly DsarOutcomeAudit $audit) {}

    /**
     * Flags every open request whose deadline passed by $at, each request in
     * its own transaction, and returns how many it flagged.
     */
    public function execute(CarbonInterface $at): int
    {
        /** @var array<int, string> $requestIds */
        $requestIds = DsarRequestRecord::query()->whereBreachDueToFlag($at)->pluck('id')->all();

        foreach ($requestIds as $requestId) {
            $this->flag($requestId, $at);
        }

        return count($requestIds);
    }

    private function flag(string $requestId, CarbonInterface $at): void
    {
        DB::transaction(function () use ($requestId, $at): void {
            DsarRequest::retrieve($requestId)->flagSlaBreach($at)->persist();
            $this->audit->record(DsarRequestRecord::query()->findOrFail($requestId), DsarSlaBreached::class, 'deadline_missed');
        });
    }
}
