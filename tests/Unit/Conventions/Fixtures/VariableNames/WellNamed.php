<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Models\User;
use Carbon\CarbonImmutable;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Entities\BreachIncidentRecord;

final class WellNamed
{
    protected RequestOrigin $requestOrigin;

    /**
     * @param  Collection<int, CompanyUser>  $companyUsers
     */
    public function __construct(
        protected BreachIncidentRecord $breachIncident,
        protected Collection $companyUsers,
    ) {}

    public function handle(User $actor, CarbonImmutable $filedAt): ?BreachIncidentRecord
    {
        $breachIncidents = BreachIncidentRecord::query();
        $newBreachIncident = $this->find($breachIncidents);

        foreach ($this->companyUsers as $companyUser) {
            $companyUser->touch();
        }

        try {
            $actor->touch();
        } catch (QueryException $queryException) {
            report($queryException);
        }

        return $filedAt->isPast() ? $newBreachIncident : null;
    }

    /**
     * @param  Builder<BreachIncidentRecord>  $breachIncidents
     */
    protected function find(Builder $breachIncidents): ?BreachIncidentRecord
    {
        return $breachIncidents->first();
    }
}
