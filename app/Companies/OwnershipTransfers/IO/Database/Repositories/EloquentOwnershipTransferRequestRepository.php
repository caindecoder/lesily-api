<?php

namespace App\Companies\OwnershipTransfers\IO\Database\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferFilters;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

class EloquentOwnershipTransferRequestRepository implements OwnershipTransferRequestRepositoryInterface
{
    /**
     * The company's default page size when the caller does not request one.
     */
    private const int DEFAULT_PER_PAGE = 15;

    public function findOpenByCompany(string $companyId): ?OwnershipTransferRequest
    {
        // The open set derives from the enum's own predicate — one source of
        // truth for "which states are open" (reviewer C1/#2).
        $openStatuses = array_filter(
            OwnershipTransferStatus::cases(),
            fn (OwnershipTransferStatus $status): bool => $status->isOpen(),
        );

        return OwnershipTransferRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $openStatuses)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, OwnershipTransferRequest>
     */
    public function paginateForCompany(string $companyId, OwnershipTransferFilters $filters): LengthAwarePaginator
    {
        return OwnershipTransferRequest::query()
            ->forCompany($companyId)
            ->withState($filters->state)
            ->applySort($filters->sort)
            ->paginate(
                perPage: $filters->per_page ?? self::DEFAULT_PER_PAGE,
                page: $filters->page ?? 1,
            );
    }

    public function findByIdForCompany(string $companyId, string $requestId): ?OwnershipTransferRequest
    {
        return OwnershipTransferRequest::query()
            ->forCompany($companyId)
            ->whereKey($requestId)
            ->first();
    }
}
