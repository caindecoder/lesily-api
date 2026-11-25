<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Foundation\AuditTrail\Values\RequestOrigin;

final class MisnamedProperty
{
    protected RequestOrigin $origin;
}
