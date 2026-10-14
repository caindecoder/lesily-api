<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Compliance\Dsar\UseCases\Responses\DsarRequestQueue;
use App\Compliance\Dsar\UseCases\Commands\DsarRequestAdminFilters;

class ReviewDsarRequestQueueInteractor
{
    /**
     * The read and its audit row share one transaction — no read without its evidence.
     */
    public function execute(DsarRequestAdminFilters $filters, User $admin, AdminRequestContext $context): DsarRequestQueue
    {
        return DB::transaction(function () use ($filters, $admin, $context): DsarRequestQueue {
            $page = $this->selectedBy($filters)
                ->with(['company', 'subject', 'requestedBy'])
                ->orderBy('sla_deadline')
                ->orderBy('id')
                // page defaults here, never through Laravel's resolver, which would
                // honour a body-supplied page past the validated query filters.
                ->paginate(perPage: $filters->per_page ?? 15, page: $filters->page ?? 1);
            $counts = $this->countInProgress($this->selectedBy($filters));

            $this->recordQueueRead($this->queryContextOf($filters, $page), $admin, $context);

            return new DsarRequestQueue($page, ...$counts);
        });
    }

    /**
     * The requests the filters select — the page and the counts read the same set (N84).
     *
     * @return Builder<DsarRequestRecord>
     */
    private function selectedBy(DsarRequestAdminFilters $filters): Builder
    {
        return DsarRequestRecord::query()
            ->when($filters->status, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters->company_id, fn (Builder $query, string $companyId) => $query->where('company_id', $companyId))
            ->when($filters->overdueOnly(), fn (Builder $query) => $query->whereOverdue(CarbonImmutable::now()))
            ->when($filters->breachedOnly(), fn (Builder $query) => $query->whereSlaBreached());
    }

    /**
     * The in-progress requests among $selected, counted in one query. Days are
     * calendar days on UTC dates (N78): overdue = due before tomorrow's start
     * (0 days or fewer left); due within seven days = from tomorrow's start to
     * before the eighth day's start.
     *
     * @param  Builder<DsarRequestRecord>  $selected
     * @return array{open: int, overdue: int, dueInSevenDays: int}
     */
    private function countInProgress(Builder $selected): array
    {
        $tomorrow = CarbonImmutable::now()->startOfDay()->addDay();

        $counts = (array) $selected
            ->where('status', DsarRequestStatus::InProgress)
            ->selectRaw(
                'count(*) as open, '
                .'sum(case when sla_deadline < ? then 1 else 0 end) as overdue, '
                .'sum(case when sla_deadline >= ? and sla_deadline < ? then 1 else 0 end) as due_in_7_days',
                [$tomorrow, $tomorrow, $tomorrow->addDays(7)],
            )
            ->toBase()
            ->sole();

        // MySQL returns sums as decimal strings, and null over no rows.
        return [
            'open' => (int) $counts['open'],
            'overdue' => (int) $counts['overdue'],
            'dueInSevenDays' => (int) $counts['due_in_7_days'],
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, DsarRequestRecord>  $page
     * @return array<string, mixed>
     */
    private function queryContextOf(DsarRequestAdminFilters $filters, LengthAwarePaginator $page): array
    {
        return [
            'filters' => array_filter(
                [
                    'status' => $filters->status,
                    'company_id' => $filters->company_id,
                    'overdue' => $filters->overdue,
                    'sla_breached' => $filters->sla_breached,
                ],
                fn (?string $value): bool => $value !== null,
            ),
            'result_count' => count($page->items()),
            'page' => $page->currentPage(),
        ];
    }

    /**
     * A queue read points at a page, not at one request — the row is
     * platform-level, its query context in metadata.
     *
     * @param  array<string, mixed>  $queryContext
     */
    private function recordQueueRead(array $queryContext, User $admin, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'event_class' => 'DsarRequestQueueViewed',
            'subject_type' => 'dsar_request_queue',
            'action' => 'dsar_queue_viewed',
            'description' => 'Platform admin viewed a page of the DSAR request queue.',
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => $queryContext,
            'recorded_at' => now(),
        ]);
    }
}
