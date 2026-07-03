<?php

namespace App\Companies\Memberships\UseCases\Repositories;

use Illuminate\Support\Collection;
use App\Companies\Entities\CompanyUser;
use App\Companies\Memberships\UseCases\Commands\MembershipFilters;

interface CompanyUserRepositoryInterface
{
    /**
     * The company's ACTIVE memberships (soft-deleted/removed members excluded —
     * UC-1-024 D5) with user + role loaded, shaped by the roster filters (UC-1-017).
     *
     * @return Collection<int, CompanyUser>
     */
    public function listForCompany(string $companyId, MembershipFilters $filters): Collection;
}
