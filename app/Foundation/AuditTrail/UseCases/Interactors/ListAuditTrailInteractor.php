<?php

namespace App\Foundation\AuditTrail\UseCases\Interactors;

use Carbon\CarbonPeriod;
use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
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
            ->when($filters->within, fn (Builder $query, CarbonPeriod $within) => $query
                ->whereDate('audit_trail.recorded_at', '>=', $within->getStartDate()->toDateString())
                ->whereDate('audit_trail.recorded_at', '<=', $within->getIncludedEndDate()->toDateString()))
            ->when($filters->actor, fn (Builder $query, string $actor) => $this->byActor($query, $actor))
            ->when($filters->action, fn (Builder $query, AuditAction $action) => $query->where('audit_trail.action', $action))
            ->when($filters->subjectType, fn (Builder $query, AuditSubjectType $type) => $query->where('audit_trail.subject_type', $type))
            ->orderByDesc('audit_trail.recorded_at')
            ->orderByDesc('audit_trail.id')
            ->paginate(perPage: $filters->perPage ?? 15, page: $filters->page ?? 1);
    }

    /**
     * @param  Builder<AuditEntry>  $query
     * @return Builder<AuditEntry>
     */
    protected function byActor(Builder $query, string $actor): Builder
    {
        if ($actor === AuditTrailFilters::LESILY_SUPPORT) {
            return $query->where('audit_trail.actor_role_code', AuditEntry::STAFF_ROLE_CODE);
        }

        return $query
            ->where('audit_trail.actor_user_id', $actor)
            ->where(fn (Builder $member) => $member
                ->whereNull('audit_trail.actor_role_code')
                ->orWhere('audit_trail.actor_role_code', '!=', AuditEntry::STAFF_ROLE_CODE));
    }
}
