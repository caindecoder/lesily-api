<?php

namespace App\Foundation\AccessLog\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Audit\AuditTrailWriter;

/**
 * Deletes access-log rows past their retention, oldest first, at most a night's cap a run.
 */
class PruneExpiredAccessLogRowsInteractor
{
    protected const int NO_COMPANY_RETENTION_YEARS = 1;

    protected const int ROWS_PER_RUN = 1000;

    public function __construct(
        protected readonly AuditTrailWriter $writer,
    ) {}

    public function execute(CarbonInterface $at): int
    {
        return DB::transaction(function () use ($at): int {
            $expired = $this->expiredRows($at->toImmutable()->startOfDay())
                ->orderBy('last_seen_at')
                ->limit(self::ROWS_PER_RUN)
                ->get(['id', 'company_id']);

            $deleted = AccessLogEntry::query()->whereIn('id', $expired->modelKeys())->delete();

            $expired->whereNotNull('company_id')->countBy('company_id')
                ->each(fn (int $count, string $companyId) => $this->audit($companyId, $count));

            return $deleted;
        });
    }

    protected function audit(string $companyId, int $count): void
    {
        $this->writer->write([
            'company_id' => $companyId,
            'stored_event_id' => null,
            'event_class' => 'AccessLogPurged',
            'subject_type' => AuditSubjectType::Company,
            'subject_id' => $companyId,
            'subject_label' => null,
            'metadata' => ['count' => $count],
        ], AuditAction::AccessLogPurged, 'info');
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
