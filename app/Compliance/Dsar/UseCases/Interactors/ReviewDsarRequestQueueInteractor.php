<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($admin, $context): DsarRequestQueue {
            $page = DsarRequestRecord::query()
                ->with(['company', 'subject', 'requestedBy'])
                ->orderBy('sla_deadline')
                ->orderBy('id')
                ->paginate();
            $counts = $this->countInProgress();

            $this->recordQueueRead($page, $admin, $context);

            return new DsarRequestQueue($page, open: (int) $counts->open, overdue: 0, dueInSevenDays: (int) $counts->due_in_7_days);
        });
    }

    /**
     * The in-progress requests, counted in one query. Days are calendar days
     * on UTC dates (N78): due within seven days = from tomorrow's start to
     * before the eighth day's start.
     */
    private function countInProgress(): object
    {
        $tomorrow = CarbonImmutable::now()->startOfDay()->addDay();

        return DsarRequestRecord::query()
            ->where('status', DsarRequestStatus::InProgress)
            ->selectRaw(
                'count(*) as open, sum(case when sla_deadline >= ? and sla_deadline < ? then 1 else 0 end) as due_in_7_days',
                [$tomorrow, $tomorrow->addDays(7)],
            )
            ->toBase()
            ->sole();
    }

    /**
     * A queue read points at a page, not at one request — the row is
     * platform-level, its query context in metadata.
     *
     * @param  LengthAwarePaginator<int, DsarRequestRecord>  $page
     */
    private function recordQueueRead(LengthAwarePaginator $page, User $admin, AdminRequestContext $context): void
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
            'metadata' => ['filters' => [], 'result_count' => count($page->items()), 'page' => $page->currentPage()],
            'recorded_at' => now(),
        ]);
    }
}
