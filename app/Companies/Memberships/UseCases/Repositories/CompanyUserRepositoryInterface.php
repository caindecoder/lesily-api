<?php

namespace App\Companies\Memberships\UseCases\Repositories;

use stdClass;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;

interface CompanyUserRepositoryInterface
{
    /**
     * The company's roster rows — ACTIVE memberships (soft-deleted/removed
     * members excluded — UC-1-024 D5) unioned with LIVE-PENDING invitations
     * (status=invited; the Task-4 union — s110 R4a/R4b) as one aliased column
     * shape, shaped by the roster filters and paginated per api-conventions §9
     * (UC-1-017).
     *
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function listForCompany(string $companyId, MembershipFilters $filters): LengthAwarePaginator;

    /**
     * One ACTIVE membership as its roster-row shape (the same aliased columns
     * MembershipResource consumes), or null when unknown / removed /
     * cross-tenant (UC-1-023 R-E s112 — the 200 envelope echoes this row).
     */
    public function findById(string $companyId, string $membershipId): ?stdClass;
}
