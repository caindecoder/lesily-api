<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Companies\Entities\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\UseCases\Commands\ListBreachNoticesCommand;

class ListBreachNoticesInteractor
{
    /**
     * @return LengthAwarePaginator<int, BreachIncidentCompany>
     */
    public function execute(ListBreachNoticesCommand $command, Company $company): LengthAwarePaginator
    {
        return BreachIncidentCompany::query()
            ->whereCompany($company)
            ->whereNotified()
            ->with(['breach', 'company'])
            ->orderByDesc('notified_at')
            ->orderByDesc('id')
            ->paginate(perPage: $command->perPage ?? 15, page: $command->page ?? 1);
    }
}
