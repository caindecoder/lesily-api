<?php

namespace App\Companies\Memberships\UseCases\Repositories;

use App\Companies\Entities\CompanyUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;

interface CompanyUserRepositoryInterface
{
    /**
     * The company's ACTIVE memberships (soft-deleted/removed members excluded —
     * UC-1-024 D5) with user + role loaded, shaped by the roster filters and
     * paginated per api-conventions §9 (UC-1-017).
     *
     * @return LengthAwarePaginator<int, CompanyUser>
     */
    public function listForCompany(string $companyId, MembershipFilters $filters): LengthAwarePaginator;
}
