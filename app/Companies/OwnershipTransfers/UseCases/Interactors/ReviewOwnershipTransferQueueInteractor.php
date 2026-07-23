<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Http\Values\AdminRequestContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferAdminFilters;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

/**
 * The platform-admin cross-tenant transfer queue (UC-1-127) — the use case
 * behind PlatformAdminOwnershipTransfersController@index: the deliberately
 * unscoped page, then the ONE append-only audit_trail row recording the read.
 *
 * A read is still a use case (E07 §4 — a use case is defined by the actor's
 * goal, not by whether it writes), and the rules that make this endpoint what
 * it is — cross-tenant scope, the audit obligation — are application policy,
 * not HTTP concerns, so they live here rather than in the controller.
 *
 * Deliberate, bounded CQS exception, same shape as UC-1-122's
 * ReviewInvoiceAsAdminInteractor: the audit insert is an orthogonal
 * access-log observation, not part of the return contract; it is append-only
 * and fires on the success path only.
 */
class ReviewOwnershipTransferQueueInteractor
{
    public function __construct(private readonly OwnershipTransferRequestRepositoryInterface $transfers) {}

    /**
     * @return LengthAwarePaginator<int, OwnershipTransferRequest>
     */
    public function execute(OwnershipTransferAdminFilters $filters, User $admin, AdminRequestContext $context): LengthAwarePaginator
    {
        $page = $this->transfers->paginateForAdminQueue($filters);

        $this->recordQueueRead($filters, $page, $admin, $context);

        return $page;
    }

    /**
     * The cd-audit-trail platform-level shape: stored_event_id NULL (a read
     * emits no domain event), and BOTH subject_id and company_id NULL — a
     * cross-tenant query names no single entity and belongs to no single
     * tenant (F3). The query context rides metadata instead.
     *
     * @param  LengthAwarePaginator<int, OwnershipTransferRequest>  $page
     */
    private function recordQueueRead(
        OwnershipTransferAdminFilters $filters,
        LengthAwarePaginator $page,
        User $admin,
        AdminRequestContext $context,
    ): void {
        AuditEntry::query()->create([
            'company_id' => null,
            'stored_event_id' => null,
            'event_class' => 'AdminOwnershipTransferQueueViewed',
            'subject_type' => 'ownership_transfer_queue',
            'subject_id' => null,
            'subject_label' => 'Ownership transfer queue',
            'action' => 'admin_queue_viewed',
            'description' => "Platform admin viewed the ownership transfer queue ({$page->total()} matching).",
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            // Client-controlled header values bounded to their varchar(255)
            // columns — MySQL strict mode 22001s an over-length insert (the
            // s137 I-1 bounded-fidelity ruling).
            'user_agent' => $this->bounded($context->userAgent),
            'request_id' => $this->bounded($context->requestId),
            'metadata' => [
                'filters' => array_filter(
                    $filters->toArray(),
                    static fn (mixed $value): bool => $value !== null,
                ),
                'result_count' => $page->count(),
                'page' => $page->currentPage(),
            ],
            'recorded_at' => now(),
        ]);
    }

    private function bounded(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, 255, '');
    }
}
