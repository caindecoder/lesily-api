<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Foundation\AuditTrail\Values\RequestOrigin;

final class MisnamedNullable
{
    public function record(?RequestOrigin $origin): ?RequestOrigin
    {
        return $origin;
    }
}
