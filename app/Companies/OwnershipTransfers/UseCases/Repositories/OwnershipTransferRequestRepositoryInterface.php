<?php

namespace App\Companies\OwnershipTransfers\UseCases\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferFilters;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferAdminFilters;

/**
 * Read seam for the transfer-request projection (CD-1-05 §3). Task-1 scope:
 * the one-open-per-company invariant lookup; Task-2 adds the tenant list +
 * detail reads (UC-1-125/126).
 */
interface OwnershipTransferRequestRepositoryInterface
{
    /**
     * The company's open (requested|verified) transfer, if one exists — at
     * most one per company (ERD-1-05 §5 invariant).
     */
    public function findOpenByCompany(string $companyId): ?OwnershipTransferRequest;

    /**
     * The company's transfer history (UC-1-126) — all states, newest-first,
     * filtered + paginated per $filters, eager-loaded via the query scope.
     *
     * @return LengthAwarePaginator<int, OwnershipTransferRequest>
     */
    public function paginateForCompany(string $companyId, OwnershipTransferFilters $filters): LengthAwarePaginator;

    /**
     * A single transfer scoped to the company (UC-1-125) — null when it does not
     * exist OR belongs to another company (existence-hidden 404, not 403).
     */
    public function findByIdForCompany(string $companyId, string $requestId): ?OwnershipTransferRequest;

    /**
     * The platform-admin triage queue (UC-1-127) — deliberately UNSCOPED by
     * company: the /api/v1/admin/* family is cross-tenant by design.
     *
     * @return LengthAwarePaginator<int, OwnershipTransferRequest>
     */
    public function paginateForAdminQueue(OwnershipTransferAdminFilters $filters): LengthAwarePaginator;

    /**
     * A single transfer resolved WITHOUT a company predicate (UC-1-127 detail) —
     * the platform-admin counterpart to findByIdForCompany. Named for the
     * boundary it crosses: a bare `findById` beside `findByIdForCompany` reads
     * as the innocent default when it is in fact the cross-tenant one.
     */
    public function findByIdAcrossTenants(string $requestId): ?OwnershipTransferRequest;

    /**
     * The transfers whose SLA deadline elapsed strictly before $asOf, as
     * `id => status` — the daily expiry scan's selection (UC-1-116 T8 /
     * UC-1-117 T9).
     *
     * Not hydrated models: the caller re-reads each aggregate from its event
     * stream anyway, so a read model would be work the transition throws away.
     * The status rides along because the pre-transition state is what selects
     * the policy window (30d requested-stage vs 14d verified-stage, ruling M2),
     * and resolving it here costs nothing on a query that already touched the row.
     *
     * @return array<string, OwnershipTransferStatus>
     */
    public function listTimerReady(CarbonInterface $asOf): array;

    /**
     * Whether $requestId may still be written to under §S10b: TRUE only when
     * the row exists AND its company is not under legal hold. The scan asks
     * this again immediately before each per-row write, because the SELECT-time
     * `legal_hold = 0` join says nothing about a hold landing mid-scan — the
     * same SELECT→write gap ruling O1 closed for `status`, on a different table
     * (F-a / OQ-1-156, Task R1). A missing row answers FALSE: for a scheduler,
     * fail-closed means skip (M1), never write.
     */
    public function clearOfLegalHold(string $requestId): bool;
}
