<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use Throwable;
use RuntimeException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarDownloadExpired;
use App\Compliance\Dsar\UseCases\Audit\DsarOutcomeAudit;

class ExpireDsarDownloadsInteractor
{
    public function __construct(private readonly DsarOutcomeAudit $dsarOutcomeAudit) {}

    /**
     * Expires every download whose window closed by $scannedAt, each request in its
     * own transaction, and returns how many it expired. A request that fails
     * is reported and left for the next run; the others still expire.
     */
    public function execute(CarbonInterface $scannedAt): int
    {
        /** @var array<int, string> $requestIds */
        $requestIds = DsarRequestRecord::query()->whereDownloadDueToExpire($scannedAt)->pluck('id')->all();
        $expired = 0;

        foreach ($requestIds as $requestId) {
            try {
                if ($this->expire($requestId, $scannedAt)) {
                    $expired++;
                }
            } catch (Throwable $throwable) {
                report(new RuntimeException("DSAR download expiry failed for request {$requestId}.", previous: $throwable));
            }
        }

        return $expired;
    }

    /**
     * Whether the request's aggregate recorded the expiry — one a concurrent
     * run already recorded is neither audited nor counted again.
     */
    protected function expire(string $requestId, CarbonInterface $scannedAt): bool
    {
        return DB::transaction(function () use ($requestId, $scannedAt): bool {
            $dsarRequest = DsarRequest::retrieve($requestId)->expireDownload($scannedAt);

            if ($dsarRequest->getRecordedEvents() === []) {
                return false;
            }

            $dsarRequest->persist();
            $this->dsarOutcomeAudit->record(DsarRequestRecord::query()->findOrFail($requestId), DsarDownloadExpired::class, AuditAction::DsarDownloadExpired);

            return true;
        });
    }
}
