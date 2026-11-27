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
use App\Compliance\Breach\UseCases\Commands\ReviewBreachQueueCommand;

class ReviewBreachQueueInteractor
{
    /**
     * The page and its audit row share one transaction — no read without its evidence.
     *
     * @return LengthAwarePaginator<int, BreachIncidentRecord>
     */
    public function execute(ReviewBreachQueueCommand $reviewBreachQueueCommand, User $platformAdmin, RequestOrigin $requestOrigin): LengthAwarePaginator
    {
        return DB::transaction(function () use ($reviewBreachQueueCommand, $platformAdmin, $requestOrigin): LengthAwarePaginator {
            $breachIncidents = BreachIncidentRecord::query()
                ->whereStage($reviewBreachQueueCommand->stage)
                ->withCount('companies')
                ->withSum('companies', 'estimated_count')
                ->orderByDeadline()
                // page defaults here, never through Laravel's resolver, which would
                // honour a body-supplied page past the validated query filters.
                ->paginate(perPage: $reviewBreachQueueCommand->perPage ?? 15, page: $reviewBreachQueueCommand->page ?? 1);

            $this->recordQueueRead($this->queryContextOf($reviewBreachQueueCommand, $breachIncidents), $platformAdmin, $requestOrigin);

            return $breachIncidents;
        });
    }

    /**
     * @param  LengthAwarePaginator<int, BreachIncidentRecord>  $lengthAwarePaginator
     * @return array<string, mixed>
     */
    protected function queryContextOf(ReviewBreachQueueCommand $reviewBreachQueueCommand, LengthAwarePaginator $lengthAwarePaginator): array
    {
        return [
            'filters' => array_filter(
                ['stage' => $reviewBreachQueueCommand->stage?->value],
                fn (?string $value): bool => $value !== null,
            ),
            'result_count' => count($lengthAwarePaginator->items()),
            'page' => $lengthAwarePaginator->currentPage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $queryContext
     */
    protected function recordQueueRead(array $queryContext, User $platformAdmin, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'event_class' => 'BreachIncidentQueueViewed',
            'subject_type' => AuditSubjectType::BreachIncidentQueue,
            'action' => AuditAction::BreachQueueViewed,
            'description' => 'Platform admin viewed a page of the breach queue.',
            'actor_user_id' => $platformAdmin->getKey(),
            'actor_role_code' => AuditEntry::STAFF_ROLE_CODE,
            'actor_label' => $platformAdmin->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'metadata' => $queryContext,
            'severity' => 'info',
            'recorded_at' => now(),
        ]);
    }
}
