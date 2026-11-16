<?php

namespace App\Foundation\AuditTrail\UseCases\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Database\Query\Builder;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogCriteria;
use App\Foundation\AuditTrail\UseCases\Rules\MemberOrLesilySupport;

/**
 * A company's audit logs: its access-log rows and its security-relevant audit-trail rows,
 * merged newest first and cut to its plan's horizon.
 */
class AuditLogRows
{
    protected const int NO_PLAN_HORIZON_YEARS = 1;

    /**
     * The first day the company's plan keeps — a company with no plan in force keeps one year.
     */
    public function retentionFrom(Company $company): CarbonImmutable
    {
        $years = $company->planInForce()?->auditLogHorizonYears() ?? self::NO_PLAN_HORIZON_YEARS;

        return CarbonImmutable::now()->startOfDay()->subYearsNoOverflow($years);
    }

    public function of(AuditLogCriteria $criteria, Company $company): Builder
    {
        $retentionFrom = $this->retentionFrom($company);
        $from = $criteria->within === null ? $retentionFrom : $retentionFrom->max($criteria->within->getStartDate());
        $until = $criteria->within?->getIncludedEndDate()->toImmutable()->addDay();

        return $this->searchedAccessRows($this->accessRows($company, $from, $until), $criteria)
            ->unionAll($this->searchedSecurityRows($this->securityRows($company, $from, $until), $criteria))
            ->orderByDesc('time')
            ->orderByDesc('id');
    }

    protected function searchedAccessRows(Builder $rows, AuditLogCriteria $criteria): Builder
    {
        return $rows
            ->when($criteria->user, fn (Builder $query, string $user) => $query->where('access_log.user_id', $user))
            ->when($criteria->event, fn (Builder $query, string $event) => $query->where('access_log.event', $event))
            ->when($criteria->ip, fn (Builder $query, string $ip) => $query->where('access_log.ip_address', $ip));
    }

    protected function searchedSecurityRows(Builder $rows, AuditLogCriteria $criteria): Builder
    {
        return $rows
            ->when($criteria->user, fn (Builder $query, string $user) => $this->byUser($query, $user))
            ->when($criteria->event, fn (Builder $query, string $event) => $query->where('action', $event))
            ->when($criteria->ip, fn (Builder $query, string $ip) => $this->exceptStaff($query->where('ip_address', $ip)));
    }

    protected function byUser(Builder $rows, string $user): Builder
    {
        if ($user === MemberOrLesilySupport::LESILY_SUPPORT) {
            return $rows->where('actor_role_code', AuditEntry::STAFF_ROLE_CODE);
        }

        return $this->exceptStaff($rows->where('actor_user_id', $user));
    }

    /**
     * A staff row's actor and address are never shown, so no search may match them.
     */
    protected function exceptStaff(Builder $rows): Builder
    {
        return $rows->where(fn (Builder $member) => $member
            ->whereNull('actor_role_code')
            ->orWhere('actor_role_code', '!=', AuditEntry::STAFF_ROLE_CODE));
    }

    protected function accessRows(Company $company, CarbonImmutable $from, ?CarbonImmutable $until): Builder
    {
        return DB::table('access_log')
            ->leftJoin('users', 'users.id', '=', 'access_log.user_id')
            ->select([
                'access_log.id',
                DB::raw("'access' as kind"),
                'access_log.last_seen_at as time',
                'access_log.first_seen_at',
                'access_log.occurrences',
                'access_log.user_id',
                'users.name as user_name',
                DB::raw('NULL as actor_role_code'),
                'access_log.event',
                'access_log.reason as outcome',
                'access_log.ip_address',
                'access_log.user_agent as device',
            ])
            ->where('access_log.company_id', $company->getKey())
            ->where('access_log.last_seen_at', '>=', $from)
            ->when($until, fn (Builder $query, CarbonImmutable $until) => $query->where('access_log.last_seen_at', '<', $until));
    }

    protected function securityRows(Company $company, CarbonImmutable $from, ?CarbonImmutable $until): Builder
    {
        return DB::table('audit_trail')
            ->select([
                'id',
                DB::raw("'audit' as kind"),
                'recorded_at as time',
                'recorded_at as first_seen_at',
                DB::raw('1 as occurrences'),
                'actor_user_id as user_id',
                'actor_label as user_name',
                'actor_role_code',
                'action as event',
                DB::raw('NULL as outcome'),
                'ip_address',
                'user_agent as device',
            ])
            ->where('company_id', $company->getKey())
            ->whereIn('action', AuditAction::SECURITY_ACTIONS)
            ->where('recorded_at', '>=', $from)
            ->when($until, fn (Builder $query, CarbonImmutable $until) => $query->where('recorded_at', '<', $until));
    }
}
