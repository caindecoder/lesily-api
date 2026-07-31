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
     * @return array<string, OwnershipTransferStatus>
     */
    public function listTimerReady(CarbonInterface $asOf): array
    {
        // Strictly before: a transfer sitting exactly ON its deadline has not
        // outlived its window yet.
        //
        // The state predicate is stated across the UC and ERD surfaces and carried
        // by the LeaveDates analog (`whereBillingStatus(Trial)`). It was briefly
        // left out on the grounds that a terminal row is already unselectable,
        // which is true but not a property of THIS query: the timer is NULL on
        // terminal rows only because a different class, the projector, clears it in
        // every terminal arm, and `NULL < cutoff` is how that clearing takes effect
        // rather than a second guard. Relying on it made the query depend on an
        // invariant maintained elsewhere and owed Task 8 a residual — an
        // `execute()` arm landing terminal without clearing the timer would have
        // been re-selected nightly and silently skipped forever (O2).
        //
        // `openStates()` rather than a literal pair, for the reason `findOpenByCompany`
        // above gives: the enum's own `isOpen()` is the single source of truth, and
        // it already names this scan as a consumer. Hardcoding the pair here would
        // have reproduced the very defect O2 was raised over — an invariant
        // maintained somewhere else — one method away from the scope that owns it.
        // (CD-1-05's `whereTimerReady()` still specifies the time half only; stale
        // w.r.t. O2, logged for the Phase-6 mirror.)
        //
        // Legal hold is SKIP-ONLY (slice-#11 D3 / OQ-1-D07): a held company's
        // transfers are simply excluded while the hold stands, and their deadlines
        // are never paused or rewritten. When the hold lifts the ORIGINAL deadline
        // applies, so the next scan may expire the row immediately — accepted and
        // documented, in preference to snapshot arithmetic nothing else needs.
        //
        // The hold is read HERE for selection and RE-CHECKED at write time by
        // clearOfLegalHold() below (F-a / OQ-1-156, Task R1) — closing the
        // SELECT→write gap UC-1-116 EF-4d / UC-1-117 EF-4e describe, whose
        // proceed-anyway branch ruling O4 had taken deliberately until R1 built
        // the re-fetch branch.
        return OwnershipTransferRequest::query()
            ->where('next_state_transition_at', '<', $asOf)
            ->openStates()
            ->whereHas('company', fn (Builder $company) => $company->where('legal_hold', false))
            ->pluck('status', 'id')
            ->all();
    }

    public function clearOfLegalHold(string $requestId): bool
    {
        // EXISTS on (row present AND company unheld): a vanished row and a held
        // company answer identically — false, skip. Fail-closed the B1 way,
        // skip-flavoured for a scheduler (M1 — nobody to hand an error to).
        return OwnershipTransferRequest::query()
            ->whereKey($requestId)
            ->whereHas('company', fn (Builder $company) => $company->where('legal_hold', false))
            ->exists();
    }
}
