<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Illuminate\Support\LazyCollection;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\Eloquent\Builder;
use App\Billing\Invoices\Entities\Invoice;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Breach\Entities\BreachIncidentRecord;

final class UnpluralLists
{
    /**
     * @param  Collection<int, CompanyUser>  $members
     * @param  Builder<BreachIncidentRecord>  $query
     * @param  LengthAwarePaginator<int, DsarRequestRecord>  $page
     * @param  CursorPaginator<int, Invoice>  $cursor
     * @param  LazyCollection<int, CompanyUser>  $rows
     */
    public function scan(
        Collection $members,
        Builder $query,
        LengthAwarePaginator $page,
        CursorPaginator $cursor,
        LazyCollection $rows,
        Builder $scope,
    ): void {
        //
    }
}
