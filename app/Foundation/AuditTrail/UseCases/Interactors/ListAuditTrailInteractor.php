<?php

namespace App\Foundation\AuditTrail\UseCases\Interactors;

use Carbon\CarbonPeriod;
use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\AuditTrail\UseCases\Rules\MemberOrLesilySupport;
use App\Foundation\AuditTrail\UseCases\Commands\ListAuditTrailCommand;

class ListAuditTrailInteractor
{
    /**
     * @return LengthAwarePaginator<int, AuditEntry>
     */
    public function execute(ListAuditTrailCommand $listAuditTrailCommand, Company $company): LengthAwarePaginator
    {
        return AuditEntry::query()
            ->leftJoin('roles', 'roles.code', '=', 'audit_trail.actor_role_code')
            ->select(['audit_trail.*', 'roles.name as actor_role_name'])
            ->where('audit_trail.company_id', $company->getKey())
            ->when($listAuditTrailCommand->within, fn (Builder $builder, CarbonPeriod $carbonPeriod) => $builder
                ->where('audit_trail.recorded_at', '>=', $carbonPeriod->getStartDate())
                ->where('audit_trail.recorded_at', '<', $carbonPeriod->getIncludedEndDate()->toImmutable()->addDay()))
            ->when($listAuditTrailCommand->actor, fn (Builder $builder, string $actor) => $this->byActor($builder, $actor))
            ->when($listAuditTrailCommand->action, fn (Builder $builder, AuditAction $auditAction) => $builder->where('audit_trail.action', $auditAction))
            ->when($listAuditTrailCommand->subjectType, fn (Builder $builder, AuditSubjectType $auditSubjectType) => $builder->where('audit_trail.subject_type', $auditSubjectType))
            ->orderByDesc('audit_trail.recorded_at')
            ->orderByDesc('audit_trail.id')
            ->paginate(perPage: $listAuditTrailCommand->perPage ?? 15, page: $listAuditTrailCommand->page ?? 1);
    }

    /**
     * @param  Builder<AuditEntry>  $auditEntries
     * @return Builder<AuditEntry>
     */
    protected function byActor(Builder $auditEntries, string $actor): Builder
    {
        if ($actor === MemberOrLesilySupport::LESILY_SUPPORT) {
            return $auditEntries->where('audit_trail.actor_role_code', AuditEntry::STAFF_ROLE_CODE);
        }

        return $auditEntries
            ->where('audit_trail.actor_user_id', $actor)
            ->where(fn (Builder $builder) => $builder
                ->whereNull('audit_trail.actor_role_code')
                ->orWhere('audit_trail.actor_role_code', '!=', AuditEntry::STAFF_ROLE_CODE));
    }
}
