<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Compliance\Breach\UseCases\Commands\BreachIncidentAdminFilters;

class ReviewBreachQueueInteractor
{
    /**
     * The page and its audit row share one transaction — no read without its evidence.
     *
     * @return LengthAwarePaginator<int, BreachIncidentRecord>
     */
    public function execute(BreachIncidentAdminFilters $filters, User $admin, RequestOrigin $context): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filters, $admin, $context): LengthAwarePaginator {
            $page = BreachIncidentRecord::query()
                // page defaults here, never through Laravel's resolver, which would
                // honour a body-supplied page past the validated query filters.
                ->paginate(perPage: $filters->perPage ?? 15, page: $filters->page ?? 1);

            $this->recordQueueRead($this->queryContextOf($filters, $page), $admin, $context);

            return $page;
        });
    }

    /**
     * @param  LengthAwarePaginator<int, BreachIncidentRecord>  $page
     * @return array<string, mixed>
     */
    protected function queryContextOf(BreachIncidentAdminFilters $filters, LengthAwarePaginator $page): array
    {
        return [
            'filters' => array_filter(
                ['stage' => $filters->stage?->value],
                fn (?string $value): bool => $value !== null,
            ),
            'result_count' => count($page->items()),
            'page' => $page->currentPage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $queryContext
     */
    protected function recordQueueRead(array $queryContext, User $admin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'event_class' => 'BreachIncidentQueueViewed',
            'subject_type' => AuditSubjectType::BreachIncidentQueue,
            'action' => AuditAction::BreachQueueViewed,
            'description' => 'Platform admin viewed a page of the breach queue.',
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => $queryContext,
            'severity' => 'info',
            'recorded_at' => now(),
        ]);
    }
}
