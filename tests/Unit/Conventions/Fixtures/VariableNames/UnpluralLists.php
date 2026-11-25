<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Companies\Entities\CompanyUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Compliance\Breach\Entities\BreachIncidentRecord;

final class UnpluralLists
{
    /**
     * @param  Collection<int, CompanyUser>  $members
     * @param  Builder<BreachIncidentRecord>  $query
     */
    public function scan(
        Collection $members,
        Builder $query,
    ): void {
        //
    }
}
