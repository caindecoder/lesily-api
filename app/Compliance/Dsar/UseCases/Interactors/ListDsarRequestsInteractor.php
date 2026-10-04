<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\UseCases\Commands\DsarRequestFilters;

class ListDsarRequestsInteractor
{
    /**
     * @return LengthAwarePaginator<int, DsarRequestRecord>
     */
    public function execute(DsarRequestFilters $filters, User $actor, Company $company): LengthAwarePaginator
    {
        return DsarRequestRecord::query()
            ->with(['subject', 'requestedBy'])
            ->whereCompany($company)
            ->whereVisibleTo($actor)
            ->paginate();
    }
}
