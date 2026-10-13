<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

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
     * own transaction, and returns how many it expired.
     */
    public function execute(CarbonInterface $at): int
    {
        $requestIds = DsarRequestRecord::query()->whereDownloadExpired($at)->pluck('id');

        foreach ($requestIds as $requestId) {
            DB::transaction(function () use ($requestId, $at): void {
                DsarRequest::retrieve($requestId)->expireDownload($at)->persist();

                $this->audit->record(DsarRequestRecord::query()->findOrFail($requestId), DsarDownloadExpired::class, 'download_expired');
            });
        }

        return $requestIds->count();
    }
}
