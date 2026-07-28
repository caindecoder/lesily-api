<?php

namespace App\Companies\OwnershipTransfers\IO\Database\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferFilters;
use App\Companies\OwnershipTransfers\UseCases\Commands\OwnershipTransferAdminFilters;
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

    public function findByIdAcrossTenants(string $requestId): ?OwnershipTransferRequest
    {
        // No company predicate — cross-tenant by design (UC-1-127).
        return OwnershipTransferRequest::query()
            ->withAdminRelations()
            ->whereKey($requestId)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, OwnershipTransferRequest>
     */
    public function paginateForAdminQueue(OwnershipTransferAdminFilters $filters): LengthAwarePaginator
    {
        // No company predicate: cross-tenant is the defining property of this
        // read (UC-1-127 Tenant Isolation — "intentionally cross-tenant").
        return OwnershipTransferRequest::query()
            ->withAdminRelations()
            ->when(
                $filters->state === null,
                fn (Builder $query) => $query->openStates(),
                fn (Builder $query) => $query->withState($filters->state),
            )
            // Inlined rather than a scope: a `forCompanyId(?string)` that silently
            // matches EVERY tenant on null sat one noun away from `forCompany()`,
            // the mandatory tenant-isolation scope — and the permissive one read
            // as the stricter. A future forCompanyId($request->company()?->getKey())
            // on a tenant path would have compiled, passed L8, and leaked.
            ->when(
                $filters->company_id !== null,
                fn (Builder $query) => $query->where('company_id', $filters->company_id),
            )
            ->byDeadlineFirst()
            ->paginate(
                perPage: $filters->per_page ?? self::DEFAULT_PER_PAGE,
                page: $filters->page ?? 1,
            );
    }

    /**
     * @return list<string>
     */
    public function listTimerReady(CarbonInterface $asOf): array
    {
        // Strictly before: a transfer sitting exactly ON its deadline has not
        // outlived its window yet.
        return OwnershipTransferRequest::query()
            ->where('next_state_transition_at', '<', $asOf)
            ->pluck('id')
            ->all();
    }
}
