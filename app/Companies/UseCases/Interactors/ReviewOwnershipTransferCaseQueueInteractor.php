<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Companies\UseCases\Commands\OwnershipTransferCaseAdminFilters;

/**
 * The admin case-queue read (UC-1-127): a deliberately cross-tenant page of
 * cases plus ONE audit row per page, written in the same transaction as the
 * read — an audit failure vetoes the read (no read without evidence,
 * slice-11R spec §4; stricter than the invoice precedent's transaction-free
 * observation). A list has no single subject, so the row is platform-level:
 * subject_id and company_id NULL, query context in metadata — validated
 * filter tokens only (an enum value and a uuid), never free text.
 */
class ReviewOwnershipTransferCaseQueueInteractor
{
    /**
     * @return LengthAwarePaginator<int, OwnershipTransferCase>
     */
    public function execute(OwnershipTransferCaseAdminFilters $filters, User $admin, AdminRequestContext $context): LengthAwarePaginator
    {
        return DB::transaction(function () use ($filters, $admin, $context): LengthAwarePaginator {
            $page = OwnershipTransferCase::query()
                ->when($filters->status, fn ($query, string $status) => $query->withStatus(OwnershipTransferCaseStatus::from($status)))
                ->when($filters->companyId, fn ($query, string $companyId) => $query->forCompanyId($companyId))
                ->newestFirst()
                // page defaults HERE, never by falling through to Laravel's resolver:
                // that reads $request->input('page'), which would honour a body-supplied
                // page and bypass the whitelisted, validated query DTO (api-conventions §10.1).
                ->paginate(perPage: $filters->perPage ?? 15, page: $filters->page ?? 1);

            $this->recordQueueRead($this->queryContextOf($filters, $page), $admin, $context);

            return $page;
        });
    }

    /**
     * @param  LengthAwarePaginator<int, OwnershipTransferCase>  $page
     * @return array<string, mixed>
     */
    private function queryContextOf(OwnershipTransferCaseAdminFilters $filters, LengthAwarePaginator $page): array
    {
        return [
            'filters' => array_filter(
                ['status' => $filters->status, 'company_id' => $filters->companyId],
                fn (?string $value): bool => $value !== null,
            ),
            'result_count' => count($page->items()),
            'page' => $page->currentPage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $queryContext
     */
    private function recordQueueRead(array $queryContext, User $admin, AdminRequestContext $context): void
    {
        // The queue read points at a PAGE, not a record.
        AuditEntry::query()->create([
            'event_class' => 'OwnershipTransferCaseQueueViewed',
            'subject_type' => AuditSubjectType::OwnershipTransferCaseQueue,
            'action' => AuditAction::CaseQueueViewed,
            'description' => 'Platform admin viewed a page of the ownership-transfer case queue.',
            'actor_user_id' => $admin->id,
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
