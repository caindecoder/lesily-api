<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Companies\Entities\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\UseCases\Commands\BreachNoticeFilters;

class ListBreachNoticesInteractor
{
    /**
     * @return LengthAwarePaginator<int, BreachIncidentCompany>
     */
    public function execute(BreachNoticeFilters $filters, Company $company): LengthAwarePaginator
    {
        return BreachIncidentCompany::query()
            ->whereCompany($company)
            ->whereNotified()
            ->orderByDesc('notified_at')
            ->orderByDesc('id')
            ->paginate(perPage: $filters->perPage ?? 15, page: $filters->page ?? 1);
    }
}
