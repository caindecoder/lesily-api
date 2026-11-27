<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Dsar\UseCases\Responses\DsarRequestQueue;
use App\Compliance\Dsar\UseCases\Commands\ReviewDsarRequestQueueCommand;

class ReviewDsarRequestQueueInteractor
{
    /**
     * The read and its audit row share one transaction — no read without its evidence.
     */
    public function execute(ReviewDsarRequestQueueCommand $reviewDsarRequestQueueCommand, User $platformAdmin, RequestOrigin $requestOrigin): DsarRequestQueue
    {
        $viewedAt = CarbonImmutable::now();

        return DB::transaction(function () use ($reviewDsarRequestQueueCommand, $viewedAt, $platformAdmin, $requestOrigin): DsarRequestQueue {
            $dsarRequests = $this->selectedBy($reviewDsarRequestQueueCommand, $viewedAt)
                ->with(['company', 'subject', 'requestedBy'])
                ->orderBy('sla_deadline')
                ->orderBy('id')
                // page defaults here, never through Laravel's resolver, which would
                // honour a body-supplied page past the validated query filters.
                ->paginate(perPage: $reviewDsarRequestQueueCommand->perPage ?? 15, page: $reviewDsarRequestQueueCommand->page ?? 1);
            $counts = $this->countInProgress($this->selectedBy($reviewDsarRequestQueueCommand, $viewedAt), $viewedAt);

            $this->recordQueueRead($this->queryContextOf($reviewDsarRequestQueueCommand, $dsarRequests), $platformAdmin, $requestOrigin);

            return new DsarRequestQueue($dsarRequests, ...$counts);
        });
    }

    /**
     * The requests the filters select on $viewedAt — the page and the counts read the same set.
     *
     * @return Builder<DsarRequestRecord>
     */
    protected function selectedBy(ReviewDsarRequestQueueCommand $reviewDsarRequestQueueCommand, CarbonImmutable $viewedAt): Builder
    {
        return DsarRequestRecord::query()
            ->when($reviewDsarRequestQueueCommand->status, fn (Builder $builder, DsarRequestStatus $dsarRequestStatus) => $builder->where('status', $dsarRequestStatus))
            ->when($reviewDsarRequestQueueCommand->companyId, fn (Builder $builder, string $companyId) => $builder->where('company_id', $companyId))
            ->when($reviewDsarRequestQueueCommand->overdue, fn (Builder $builder) => $builder->whereOverdue($viewedAt))
            ->when($reviewDsarRequestQueueCommand->slaBreached, fn (Builder $builder) => $builder->whereSlaBreached());
    }

    /**
     * The in-progress requests among $dsarRequests, counted in one query. Days are
     * calendar days on UTC dates: overdue = due before tomorrow's start
     * (0 days or fewer left); due within seven days = from tomorrow's start to
     * before the eighth day's start.
     *
     * @param  Builder<DsarRequestRecord>  $dsarRequests
     * @return array{open: int, overdue: int, dueInSevenDays: int}
     */
    protected function countInProgress(Builder $dsarRequests, CarbonImmutable $viewedAt): array
    {
        $tomorrowStartsAt = $viewedAt->startOfDay()->addDay();

        /** @var array{open: int, overdue: string|null, due_in_7_days: string|null} $counts */
        $counts = (array) $dsarRequests
            ->where('status', DsarRequestStatus::InProgress)
            ->selectRaw('count(*) as open')
            ->selectRaw('sum(case when sla_deadline < ? then 1 else 0 end) as overdue', [$tomorrowStartsAt])
            ->selectRaw('sum(case when sla_deadline >= ? and sla_deadline < ? then 1 else 0 end) as due_in_7_days', [$tomorrowStartsAt, $tomorrowStartsAt->addDays(7)])
            ->toBase()
            ->sole();

        // MySQL returns sums as decimal strings, and null over no rows.
        return [
            'open' => $counts['open'],
            'overdue' => (int) $counts['overdue'],
            'dueInSevenDays' => (int) $counts['due_in_7_days'],
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, DsarRequestRecord>  $dsarRequests
     * @return array<string, mixed>
     */
    protected function queryContextOf(ReviewDsarRequestQueueCommand $reviewDsarRequestQueueCommand, LengthAwarePaginator $dsarRequests): array
    {
        return [
            'filters' => array_filter(
                [
                    'status' => $reviewDsarRequestQueueCommand->status?->value,
                    'company_id' => $reviewDsarRequestQueueCommand->companyId,
                    'overdue' => $this->asQueryValue($reviewDsarRequestQueueCommand->overdue),
                    'sla_breached' => $this->asQueryValue($reviewDsarRequestQueueCommand->slaBreached),
                ],
                fn (?string $value): bool => $value !== null,
            ),
            'result_count' => count($dsarRequests->items()),
            'page' => $dsarRequests->currentPage(),
        ];
    }

    protected function asQueryValue(?bool $flag): ?string
    {
        return match ($flag) {
            null => null,
            true => 'true',
            false => 'false',
        };
    }

    /**
     * A queue read points at a page, not at one request — the row is
     * platform-level, its query context in metadata.
     *
     * @param  array<string, mixed>  $queryContext
     */
    protected function recordQueueRead(array $queryContext, User $platformAdmin, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'event_class' => 'DsarRequestQueueViewed',
            'subject_type' => AuditSubjectType::DsarRequestQueue,
            'action' => AuditAction::DsarQueueViewed,
            'description' => 'Platform admin viewed a page of the DSAR request queue.',
            'actor_user_id' => $platformAdmin->getKey(),
            'actor_role_code' => AuditEntry::STAFF_ROLE_CODE,
            'actor_label' => $platformAdmin->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'metadata' => $queryContext,
            'recorded_at' => now(),
        ]);
    }
}
