<?php

namespace App\Foundation\AuditTrail\UseCases\Interactors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
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

        $rows = DB::table('audit_trail')
            ->select(['id', 'recorded_at as time'])
            ->where('company_id', $company->getKey())
            ->whereIn('action', self::SECURITY_ACTIONS)
            ->orderByDesc('time')
            ->orderByDesc('id')
            ->paginate(perPage: $filters->perPage ?? 15, page: $filters->page ?? 1);

        return new AuditLogPage($rows, $retentionFrom);
    }
}
