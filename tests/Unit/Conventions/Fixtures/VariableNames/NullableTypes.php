<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Companies\Entities\CompanyUser;
use App\Foundation\AuditTrail\Values\RequestOrigin;

final class NullableTypes
{
    public function find(?RequestOrigin $requestOrigin): ?CompanyUser
    {
        $companyUser = CompanyUser::query()->first();

        return $requestOrigin === null ? null : $companyUser;
    }
}
