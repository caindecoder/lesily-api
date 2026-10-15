<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\UseCases\Commands\UpdateCompanyCommand;

/**
 * Update a company's editable details (UC-1-033). The delivery gates run upstream
 * (auth.company membership + permission:companies.update) and the command whitelist
 * already refused immutable/unknown fields, so the interactor simply drives the
 * guardless Company aggregate and returns the refreshed read model for the response.
 */
class UpdateCompanyDetailsInteractor
{
    public function execute(Company $company, User $actor, UpdateCompanyCommand $command): Company
    {
        /** @var string $actorId users are UUID-keyed (HasUuids) */
        $actorId = $actor->getKey();

        DB::transaction(fn () => CompanyAggregate::retrieve($company->getKey())
            ->updateDetails($command, $actorId)
            ->persist());

        return $company->refresh();
    }
}
