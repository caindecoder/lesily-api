<?php

namespace App\Companies\OwnershipTransfers\UseCases\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
     * Ids of the transfers whose SLA deadline elapsed strictly before $asOf —
     * the daily expiry scan's selection (UC-1-116 T8 / UC-1-117 T9).
     *
     * Returns ids rather than models: the caller re-reads each aggregate from
     * its event stream anyway, so hydrating read models here would be work the
     * transition throws away. Both built scans do the same.
     *
     * @return list<string>
     */
    public function listTimerReady(CarbonInterface $asOf): array;
}
