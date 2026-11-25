<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Foundation\AuditTrail\Values\RequestOrigin;

final class MisnamedPromotedParameter
{
    public function __construct(protected RequestOrigin $origin) {}
}
