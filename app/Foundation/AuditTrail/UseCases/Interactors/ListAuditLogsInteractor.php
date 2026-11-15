<?php

namespace App\Foundation\AuditTrail\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Database\Query\Builder;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\AuditLogPage;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogFilters;

class ListAuditLogsInteractor
{
    /**
     * The audit-trail actions the audit logs show beside the access log.
     *
     * @var list<AuditAction>
     */
    protected const array SECURITY_ACTIONS = [
        AuditAction::PasswordChanged,
        AuditAction::PasswordReset,
        AuditAction::TwoFactorEnabled,
        AuditAction::TwoFactorDisabled,
        AuditAction::RecoveryCodesRegenerated,
        AuditAction::RoleChanged,
        AuditAction::MemberRemoved,
        AuditAction::DsarDownloaded,
    ];

    protected const int NO_PLAN_HORIZON_YEARS = 1;

    public function execute(AuditLogFilters $filters, Company $company): AuditLogPage
    {
        $retentionFrom = $this->retentionFrom($company);
        $from = $filters->within === null ? $retentionFrom : $retentionFrom->max($filters->within->getStartDate());
        $until = $filters->within?->getIncludedEndDate()->toImmutable()->addDay();

        $rows = $this->accessRows($company, $from, $until)
            ->unionAll($this->securityRows($company, $from, $until))
            ->orderByDesc('time')
            ->orderByDesc('id')
            ->paginate(perPage: $filters->perPage ?? 15, page: $filters->page ?? 1);

        return new AuditLogPage($rows, $retentionFrom);
    }

    /**
     * The first day the company's plan keeps — a company with no plan in force keeps one year.
     */
    protected function retentionFrom(Company $company): CarbonImmutable
    {
        $years = $company->planInForce()?->auditLogHorizonYears() ?? self::NO_PLAN_HORIZON_YEARS;

        return CarbonImmutable::now()->startOfDay()->subYearsNoOverflow($years);
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
            ->whereIn('action', self::SECURITY_ACTIONS)
            ->where('recorded_at', '>=', $from)
            ->when($until, fn (Builder $query, CarbonImmutable $until) => $query->where('recorded_at', '<', $until));
    }
}
