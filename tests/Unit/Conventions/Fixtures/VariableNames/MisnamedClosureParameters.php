<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Closure;
use App\Companies\Entities\CompanyUser;
use App\Foundation\AuditTrail\Values\RequestOrigin;

final class MisnamedClosureParameters
{
    public function describe(): Closure
    {
        return function (RequestOrigin $origin): string {
            return $origin::class;
        };
    }

    public function key(): Closure
    {
        return fn (CompanyUser $membership): mixed => $membership->getKey();
    }
}
