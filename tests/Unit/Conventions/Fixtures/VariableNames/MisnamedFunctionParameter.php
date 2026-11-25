<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Foundation\AuditTrail\Values\RequestOrigin;

function describeOrigin(RequestOrigin $origin): string
{
    return $origin::class;
}
