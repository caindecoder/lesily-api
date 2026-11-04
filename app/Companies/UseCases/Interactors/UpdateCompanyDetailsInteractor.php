<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanySettings;
use App\Companies\Aggregates\SettingsBaseline;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\UseCases\Commands\UpdateCompanyCommand;

/**
 * Update a company's editable details (UC-1-033). The delivery gates run upstream
 * (auth.company membership + permission:companies.update) and the command whitelist
 * already refused immutable/unknown fields. The settings row is read under its lock
 * so the aggregate decides against the company's current own values (UC-1-058).
 */
class UpdateCompanyDetailsInteractor
{
    public function execute(Company $company, User $actor, UpdateCompanyCommand $command): Company
    {
        /** @var string $actorId users are UUID-keyed (HasUuids) */
        $actorId = $actor->getKey();

        DB::transaction(function () use ($company, $command, $actorId): void {
            $settings = CompanySettings::query()
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->sole();

            CompanyAggregate::retrieve($company->getKey())
                ->updateDetails($command, SettingsBaseline::of($settings), $actorId)
                ->persist();
        });

        return $company->refresh();
    }
}
