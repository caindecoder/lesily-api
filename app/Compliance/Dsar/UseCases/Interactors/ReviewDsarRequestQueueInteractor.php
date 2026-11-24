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
    public function execute(ReviewDsarRequestQueueCommand $command, User $admin, RequestOrigin $context): DsarRequestQueue
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($command, $now, $admin, $context): DsarRequestQueue {
            $page = $this->selectedBy($command, $now)
                ->with(['company', 'subject', 'requestedBy'])
                ->orderBy('sla_deadline')
                ->orderBy('id')
                // page defaults here, never through Laravel's resolver, which would
                // honour a body-supplied page past the validated query filters.
                ->paginate(perPage: $command->perPage ?? 15, page: $command->page ?? 1);
            $counts = $this->countInProgress($this->selectedBy($command, $now), $now);

            $this->recordQueueRead($this->queryContextOf($command, $page), $admin, $context);

            return new DsarRequestQueue($page, ...$counts);
        });
    }

    /**
     * The requests the filters select on $now — the page and the counts read the same set.
     *
     * @return Builder<DsarRequestRecord>
     */
    protected function selectedBy(ReviewDsarRequestQueueCommand $command, CarbonImmutable $now): Builder
    {
        return DsarRequestRecord::query()
            ->when($command->status, fn (Builder $query, DsarRequestStatus $status) => $query->where('status', $status))
            ->when($command->companyId, fn (Builder $query, string $companyId) => $query->where('company_id', $companyId))
            ->when($command->overdue, fn (Builder $query) => $query->whereOverdue($now))
            ->when($command->slaBreached, fn (Builder $query) => $query->whereSlaBreached());
    }

    /**
     * The in-progress requests among $selected, counted in one query. Days are
     * calendar days on UTC dates: overdue = due before tomorrow's start
     * (0 days or fewer left); due within seven days = from tomorrow's start to
     * before the eighth day's start.
     *
     * @param  Builder<DsarRequestRecord>  $selected
     * @return array{open: int, overdue: int, dueInSevenDays: int}
     */
    protected function countInProgress(Builder $selected, CarbonImmutable $now): array
    {
        $tomorrow = $now->startOfDay()->addDay();

        /** @var array{open: int, overdue: string|null, due_in_7_days: string|null} $counts */
        $counts = (array) $selected
            ->where('status', DsarRequestStatus::InProgress)
            ->selectRaw('count(*) as open')
            ->selectRaw('sum(case when sla_deadline < ? then 1 else 0 end) as overdue', [$tomorrow])
            ->selectRaw('sum(case when sla_deadline >= ? and sla_deadline < ? then 1 else 0 end) as due_in_7_days', [$tomorrow, $tomorrow->addDays(7)])
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
     * @param  LengthAwarePaginator<int, DsarRequestRecord>  $page
     * @return array<string, mixed>
     */
    protected function queryContextOf(ReviewDsarRequestQueueCommand $command, LengthAwarePaginator $page): array
    {
        return [
            'filters' => array_filter(
                [
                    'status' => $command->status?->value,
                    'company_id' => $command->companyId,
                    'overdue' => $this->asQueryValue($command->overdue),
                    'sla_breached' => $this->asQueryValue($command->slaBreached),
                ],
                fn (?string $value): bool => $value !== null,
            ),
            'result_count' => count($page->items()),
            'page' => $page->currentPage(),
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
    protected function recordQueueRead(array $queryContext, User $admin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'event_class' => 'DsarRequestQueueViewed',
            'subject_type' => AuditSubjectType::DsarRequestQueue,
            'action' => AuditAction::DsarQueueViewed,
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
