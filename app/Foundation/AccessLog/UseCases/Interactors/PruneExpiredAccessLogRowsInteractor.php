<?php

namespace App\Foundation\AccessLog\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Foundation\AccessLog\Entities\AccessLogEntry;

class PruneExpiredAccessLogRowsInteractor
{
    protected const int NO_COMPANY_RETENTION_YEARS = 1;

    public function execute(CarbonInterface $at): int
    {
        $day = $at->toImmutable()->startOfDay();

        $withoutCompany = AccessLogEntry::query()
            ->whereNull('company_id')
            ->where('last_seen_at', '<', $this->cutoff($day, self::NO_COMPANY_RETENTION_YEARS))
            ->delete();

        $withCompany = $this->retentions()->sum(fn (int $years): int => AccessLogEntry::query()
            ->whereIn('company_id', DB::table('companies')->select('id')->where('access_log_retention_years', $years))
            ->where('last_seen_at', '<', $this->cutoff($day, $years))
            ->delete());

        return $withoutCompany + $withCompany;
    }

    /**
     * @return Collection<int, int>
     */
    protected function retentions(): Collection
    {
        /** @var Collection<int, int> */
        return DB::table('companies')->distinct()->pluck('access_log_retention_years');
    }

    protected function cutoff(CarbonImmutable $day, int $years): CarbonImmutable
    {
        return $day->subYearsNoOverflow($years);
    }
}
