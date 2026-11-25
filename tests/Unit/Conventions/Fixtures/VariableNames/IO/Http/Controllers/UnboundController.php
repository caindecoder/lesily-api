<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames\IO\Http\Controllers;

use App\Companies\Entities\Company;
use App\Foundation\AuditTrail\Values\RequestOrigin;

final class UnboundController
{
    public function store(
        RequestOrigin $origin,
        Company $tenant,
    ): mixed {
        return $tenant->getKey() ?? $origin;
    }
}
