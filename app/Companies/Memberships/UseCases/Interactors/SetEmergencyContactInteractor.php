<?php

namespace App\Companies\Memberships\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Companies\Memberships\UseCases\Commands\SetEmergencyContactCommand;

/**
 * The owner replaces their emergency contact for one company (UC-1-029).
 */
class SetEmergencyContactInteractor
{
    public function execute(Company $company, User $owner, SetEmergencyContactCommand $command): CompanyUser
    {
        DB::transaction(function () use ($company, $owner, $command): void {
            UserAggregate::retrieve($owner->getKey())
                ->setEmergencyContact($company->getKey(), $command)
                ->persist();
        });

        return CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $owner->getKey())
            ->sole();
    }
}
