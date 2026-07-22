<?php

namespace App\Companies\OwnershipTransfers\UseCases\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferFilters;

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
     * filtered + paginated per $filters, eager-loaded via the custom builder.
     *
     * @return LengthAwarePaginator<int, OwnershipTransferRequest>
     */
    public function paginateForCompany(string $companyId, OwnershipTransferFilters $filters): LengthAwarePaginator;
}
