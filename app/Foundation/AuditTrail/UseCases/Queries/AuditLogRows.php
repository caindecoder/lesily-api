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
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogFilters;
use App\Foundation\AuditTrail\UseCases\Rules\MemberOrLesilySupport;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogExportFilters;

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
    public function newestFirst(AuditLogExportFilters $filters, CarbonImmutable $before): LazyCollection
    {
        return LazyCollection::make(function () use ($filters, $before) {
            $last = null;

            do {
                $page = $this->merged($this->halves($filters, $filters->company, $before), $last)->limit($this->pageSize)->get();

                foreach ($page as $row) {
                    yield $row;
                }

                $last = $page->last();
            } while ($page->count() === $this->pageSize);
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
    public function from(AuditLogFilters|AuditLogExportFilters $filters, Company $company): CarbonImmutable
    {
        $retentionFrom = $this->retentionFrom($company);

        return $filters->within === null ? $retentionFrom : $retentionFrom->max($filters->within->getStartDate());
    }

    /**
     * @param  CarbonImmutable|null  $before  a moment the rows must come before (an export's start)
     */
    public function of(AuditLogFilters|AuditLogExportFilters $filters, Company $company, ?CarbonImmutable $before = null): Builder
    {
        return $this->merged($this->halves($filters, $company, $before), null);
    }

    /**
     * The access-log half and the audit-trail half, each filtered and cut to the range.
     *
     * @return array{Builder, Builder}
     */
    protected function halves(AuditLogFilters|AuditLogExportFilters $filters, Company $company, ?CarbonImmutable $before): array
    {
        $from = $this->from($filters, $company);
        $until = $filters->within?->getIncludedEndDate()->toImmutable()->addDay();
        $until = $before === null ? $until : ($until?->min($before) ?? $before);

        return [
            $this->searchedAccessRows($this->accessRows($company, $from, $until), $filters),
            $this->searchedSecurityRows($this->securityRows($company, $from, $until), $filters),
        ];
    }

    /**
     * The two halves as one list, newest first; with a row given, only the rows after it.
     *
     * @param  array{Builder, Builder}  $halves
     */
    protected function merged(array $halves, ?stdClass $after): Builder
    {
        [$access, $security] = $halves;

        if ($after !== null) {
            $access = $this->olderThan($access, $after, 'access_log.last_seen_at');
            $security = $this->olderThan($security, $after, 'audit_trail.recorded_at');
        }

        return $access->unionAll($security)->orderByDesc('time')->orderByDesc('id');
    }

    /**
     * The rows that come after $row in the newest-first order: older, or as old with a lower id.
     */
    protected function olderThan(Builder $rows, stdClass $row, string $timeColumn): Builder
    {
        $idColumn = Str::before($timeColumn, '.').'.id';

        return $rows->where(fn (Builder $older) => $older
            ->where($timeColumn, '<', $row->time)
            ->orWhere(fn (Builder $sameTime) => $sameTime->where($timeColumn, $row->time)->where($idColumn, '<', $row->id)));
    }

    protected function searchedAccessRows(Builder $rows, AuditLogFilters|AuditLogExportFilters $filters): Builder
    {
        return $rows
            ->when($filters->user, fn (Builder $query, string $user) => $query->where('access_log.user_id', $user))
            ->when($filters->event, fn (Builder $query, string $event) => $query->where('access_log.event', $event))
            ->when($filters->ip, fn (Builder $query, string $ip) => $query->where('access_log.ip_address', $ip));
    }

    protected function searchedSecurityRows(Builder $rows, AuditLogFilters|AuditLogExportFilters $filters): Builder
    {
        return $rows
            ->when($filters->user, fn (Builder $query, string $user) => $this->byUser($query, $user))
            ->when($filters->event, fn (Builder $query, string $event) => $query->where('action', $event))
            ->when($filters->ip, fn (Builder $query, string $ip) => $this->exceptStaff($query->where('ip_address', $ip)));
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
