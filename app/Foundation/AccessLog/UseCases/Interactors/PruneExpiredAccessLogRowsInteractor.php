<?php

namespace App\Foundation\AccessLog\UseCases\Interactors;

use Carbon\CarbonInterface;
use App\Foundation\AccessLog\Entities\AccessLogEntry;

class PruneExpiredAccessLogRowsInteractor
{
    protected const int NO_COMPANY_RETENTION_YEARS = 1;

    public function execute(CarbonInterface $at): int
    {
        return AccessLogEntry::query()
            ->whereNull('company_id')
            ->where('last_seen_at', '<', $at->toImmutable()->startOfDay()->subYearsNoOverflow(self::NO_COMPANY_RETENTION_YEARS))
            ->delete();
    }
}
