<?php

namespace App\Foundation\AuditTrail\UseCases\Queries;

use stdClass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\LazyCollection;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\UseCases\Rules\MemberOrLesilySupport;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogsQueryCommand;
use App\Foundation\AuditTrail\UseCases\Commands\ExportAuditLogsCommand;

/**
 * A company's audit logs: its access-log rows and its security-relevant audit-trail rows,
 * merged newest first and cut to its plan's horizon.
 */
class AuditLogRows
{
    protected const int NO_PLAN_HORIZON_YEARS = 1;

    public function __construct(protected int $pageSize = 1000) {}

    /**
     * Every row, newest first, read a page at a time; each page starts after the last row read,
     * so a row that leaves the list mid-read never shifts the pages after it.
     *
     * @return LazyCollection<int, stdClass>
     */
    public function newestFirst(ExportAuditLogsCommand $exportAuditLogsCommand, CarbonImmutable $exportStartedAt): LazyCollection
    {
        return LazyCollection::make(function () use ($exportAuditLogsCommand, $exportStartedAt) {
            $lastStdClass = null;

            do {
                $stdClasses = $this->merged($this->halves($exportAuditLogsCommand, $exportAuditLogsCommand->company, $exportStartedAt), $lastStdClass)->limit($this->pageSize)->get();

                foreach ($stdClasses as $stdClass) {
                    yield $stdClass;
                }

                $lastStdClass = $stdClasses->last();
            } while ($stdClasses->count() === $this->pageSize);
        });
    }

    /**
     * The first day the company's plan keeps — a company with no plan in force keeps one year.
     */
    public function retentionFrom(Company $company): CarbonImmutable
    {
        $years = $company->planInForce()?->auditLogHorizonYears() ?? self::NO_PLAN_HORIZON_YEARS;

        return CarbonImmutable::now()->startOfDay()->subYearsNoOverflow($years);
    }

    /**
     * The first day the rows start on: the requested start, clamped to the plan's horizon.
     */
    public function from(AuditLogsQueryCommand $auditLogsQueryCommand, Company $company): CarbonImmutable
    {
        $retentionStartDate = $this->retentionFrom($company);

        return $auditLogsQueryCommand->within === null ? $retentionStartDate : $retentionStartDate->max($auditLogsQueryCommand->within->getStartDate());
    }

    /**
     * @param  CarbonImmutable|null  $exportStartedAt  a moment the rows must come before (an export's start)
     */
    public function of(AuditLogsQueryCommand $auditLogsQueryCommand, Company $company, ?CarbonImmutable $exportStartedAt = null): Builder
    {
        return $this->merged($this->halves($auditLogsQueryCommand, $company, $exportStartedAt), null);
    }

    /**
     * The access-log half and the audit-trail half, each filtered and cut to the range.
     *
     * @return array{Builder, Builder}
     */
    protected function halves(AuditLogsQueryCommand $auditLogsQueryCommand, Company $company, ?CarbonImmutable $exportStartedAt): array
    {
        $windowStartsAt = $this->from($auditLogsQueryCommand, $company);
        $windowEndsAt = $auditLogsQueryCommand->within?->getIncludedEndDate()->toImmutable()->addDay();
        $windowEndsAt = $exportStartedAt === null ? $windowEndsAt : ($windowEndsAt?->min($exportStartedAt) ?? $exportStartedAt);

        return [
            $this->searchedAccessRows($this->accessRows($company, $windowStartsAt, $windowEndsAt), $auditLogsQueryCommand),
            $this->searchedSecurityRows($this->securityRows($company, $windowStartsAt, $windowEndsAt), $auditLogsQueryCommand),
        ];
    }

    /**
     * The two halves as one list, newest first; with a row given, only the rows after it.
     *
     * @param  array{Builder, Builder}  $halves
     */
    protected function merged(array $halves, ?stdClass $stdClass): Builder
    {
        [$accessBuilder, $securityBuilder] = $halves;

        if ($stdClass !== null) {
            $accessBuilder = $this->olderThan($accessBuilder, $stdClass, 'access_log.last_seen_at');
            $securityBuilder = $this->olderThan($securityBuilder, $stdClass, 'audit_trail.recorded_at');
        }

        return $accessBuilder->unionAll($securityBuilder)->orderByDesc('time')->orderByDesc('id');
    }

    /**
     * The rows that come after $stdClass in the newest-first order: older, or as old with a lower id.
     */
    protected function olderThan(Builder $builder, stdClass $stdClass, string $timeColumn): Builder
    {
        $idColumn = Str::before($timeColumn, '.').'.id';

        return $builder->where(fn (Builder $builder) => $builder
            ->where($timeColumn, '<', $stdClass->time)
            ->orWhere(fn (Builder $builder) => $builder->where($timeColumn, $stdClass->time)->where($idColumn, '<', $stdClass->id)));
    }

    protected function searchedAccessRows(Builder $builder, AuditLogsQueryCommand $auditLogsQueryCommand): Builder
    {
        return $builder
            ->when($auditLogsQueryCommand->user, fn (Builder $builder, string $user) => $builder->where('access_log.user_id', $user))
            ->when($auditLogsQueryCommand->event, fn (Builder $builder, string $event) => $builder->where('access_log.event', $event))
            ->when($auditLogsQueryCommand->ip, fn (Builder $builder, string $ip) => $builder->where('access_log.ip_address', $ip));
    }

    protected function searchedSecurityRows(Builder $builder, AuditLogsQueryCommand $auditLogsQueryCommand): Builder
    {
        return $builder
            ->when($auditLogsQueryCommand->user, fn (Builder $builder, string $user) => $this->byUser($builder, $user))
            ->when($auditLogsQueryCommand->event, fn (Builder $builder, string $event) => $builder->where('action', $event))
            ->when($auditLogsQueryCommand->ip, fn (Builder $builder, string $ip) => $this->exceptStaff($builder->where('ip_address', $ip)));
    }

    protected function byUser(Builder $builder, string $user): Builder
    {
        if ($user === MemberOrLesilySupport::LESILY_SUPPORT) {
            return $builder->where('actor_role_code', AuditEntry::STAFF_ROLE_CODE);
        }

        return $this->exceptStaff($builder->where('actor_user_id', $user));
    }

    /**
     * A staff row's actor and address are never shown, so no search may match them.
     */
    protected function exceptStaff(Builder $builder): Builder
    {
        return $builder->where(fn (Builder $builder) => $builder
            ->whereNull('actor_role_code')
            ->orWhere('actor_role_code', '!=', AuditEntry::STAFF_ROLE_CODE));
    }

    protected function accessRows(Company $company, CarbonImmutable $windowStartsAt, ?CarbonImmutable $windowEndsAt): Builder
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
            ->where('access_log.last_seen_at', '>=', $windowStartsAt)
            ->when($windowEndsAt, fn (Builder $builder, CarbonImmutable $windowEndsAt) => $builder->where('access_log.last_seen_at', '<', $windowEndsAt));
    }

    protected function securityRows(Company $company, CarbonImmutable $windowStartsAt, ?CarbonImmutable $windowEndsAt): Builder
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
            ->where('recorded_at', '>=', $windowStartsAt)
            ->when($windowEndsAt, fn (Builder $builder, CarbonImmutable $windowEndsAt) => $builder->where('recorded_at', '<', $windowEndsAt));
    }
}
