<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Companies\Entities\CompanyUser;

final class MisnamedForeachValue
{
    /**
     * @param  list<CompanyUser>  $companyUsers
     */
    public function count(array $companyUsers): int
    {
        $total = 0;

        foreach ($companyUsers as $membership) {
            $total += $membership->getKey() === null ? 0 : 1;
        }

        return $total;
    }
}
