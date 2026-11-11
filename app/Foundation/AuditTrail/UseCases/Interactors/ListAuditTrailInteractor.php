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
            ->leftJoin('roles', 'roles.code', '=', 'audit_trail.actor_role_code')
            ->select(['audit_trail.*', 'roles.name as actor_role_name'])
            ->where('audit_trail.company_id', $company->getKey())
            ->orderByDesc('audit_trail.recorded_at')
            ->orderByDesc('audit_trail.id')
            ->paginate(perPage: $filters->perPage ?? 15, page: $filters->page ?? 1);
    }
}
