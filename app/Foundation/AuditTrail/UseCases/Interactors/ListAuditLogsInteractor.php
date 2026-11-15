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
     * The audit-trail actions the audit logs show beside the access log (AR16).
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

    public function execute(AuditLogFilters $filters, Company $company): AuditLogPage
    {
        $retentionFrom = CarbonImmutable::now()->startOfDay()->subYearsNoOverflow(7);

        $rows = $this->accessRows($company)
            ->unionAll($this->securityRows($company))
            ->orderByDesc('time')
            ->orderByDesc('id')
            ->paginate(perPage: $filters->perPage ?? 15, page: $filters->page ?? 1);

        return new AuditLogPage($rows, $retentionFrom);
    }

    protected function accessRows(Company $company): Builder
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
            ->where('access_log.company_id', $company->getKey());
    }

    protected function securityRows(Company $company): Builder
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
            ->whereIn('action', self::SECURITY_ACTIONS);
    }
}
