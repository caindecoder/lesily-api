<?php

namespace App\Foundation\AuditTrail\UseCases\Interactors;

use App\Companies\Entities\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\UseCases\Commands\AuditTrailFilters;

class ListAuditTrailInteractor
{
    /**
     * @return LengthAwarePaginator<int, AuditEntry>
     */
    public function execute(AuditTrailFilters $filters, Company $company): LengthAwarePaginator
    {
        return AuditEntry::query()
            ->where('company_id', $company->getKey())
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate(perPage: $filters->perPage ?? 15, page: $filters->page ?? 1);
    }
}
