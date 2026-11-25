<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Companies\Entities\CompanyUser;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Database\Eloquent\Collection;

final class PrefixedTypeNames
{
    /**
     * @param  Collection<int, CompanyUser>  $activeCompanyUsers
     */
    public function rotate(
        PersonalAccessToken $currentPersonalAccessToken,
        Collection $activeCompanyUsers,
    ): void {
        //
    }
}
