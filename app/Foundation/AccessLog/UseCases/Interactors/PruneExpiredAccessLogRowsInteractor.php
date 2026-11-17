<?php

namespace App\Foundation\AccessLog\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use App\Foundation\AccessLog\Entities\AccessLogEntry;

/**
 * Deletes access-log rows past their retention, oldest first, at most a night's cap a run.
 */
class PruneExpiredAccessLogRowsInteractor
{
    protected const int NO_COMPANY_RETENTION_YEARS = 1;

    protected const int ROWS_PER_RUN = 1000;

    public function execute(CarbonInterface $at): int
    {
        $expiredIds = $this->expiredRows($at->toImmutable()->startOfDay())
            ->orderBy('last_seen_at')
            ->limit(self::ROWS_PER_RUN)
            ->pluck('id');

        return AccessLogEntry::query()->whereIn('id', $expiredIds)->delete();
    }

    /**
     * @return Builder<AccessLogEntry>
     */
    protected function expiredRows(CarbonImmutable $day): Builder
    {
        return AccessLogEntry::query()->where(function (Builder $expired) use ($day): void {
            $expired->where(fn (Builder $row) => $row
                ->whereNull('company_id')
                ->where('last_seen_at', '<', $this->cutoff($day, self::NO_COMPANY_RETENTION_YEARS)));

            foreach ($this->retentions() as $years) {
                $expired->orWhere(fn (Builder $row) => $row
                    ->whereIn('company_id', DB::table('companies')->select('id')->where('access_log_retention_years', $years))
                    ->where('last_seen_at', '<', $this->cutoff($day, $years)));
            }
        });
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
