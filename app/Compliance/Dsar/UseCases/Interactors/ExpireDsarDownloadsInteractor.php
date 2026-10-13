<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use Throwable;
use RuntimeException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarDownloadExpired;
use App\Compliance\Dsar\UseCases\Audit\DsarOutcomeAudit;

class ExpireDsarDownloadsInteractor
{
    public function __construct(private readonly DsarOutcomeAudit $audit) {}

    /**
     * Expires every download whose window closed by $at, each request in its
     * own transaction, and returns how many it expired. A request that fails
     * is reported and left for the next run; the others still expire.
     */
    public function execute(CarbonInterface $at): int
    {
        /** @var array<int, string> $requestIds */
        $requestIds = DsarRequestRecord::query()->whereDownloadExpired($at)->pluck('id')->all();
        $expired = 0;

        foreach ($requestIds as $requestId) {
            try {
                $this->expire($requestId, $at);
                $expired++;
            } catch (Throwable $failure) {
                report(new RuntimeException("DSAR download expiry failed for request {$requestId}.", previous: $failure));
            }
        }

        return $expired;
    }

    private function expire(string $requestId, CarbonInterface $at): void
    {
        DB::transaction(function () use ($requestId, $at): void {
            DsarRequest::retrieve($requestId)->expireDownload($at)->persist();

            $this->audit->record(DsarRequestRecord::query()->findOrFail($requestId), DsarDownloadExpired::class, 'download_expired');
        });
    }
}
