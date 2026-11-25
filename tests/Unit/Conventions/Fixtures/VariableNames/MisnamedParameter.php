<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Foundation\AuditTrail\Values\RequestOrigin;

final class MisnamedParameter
{
    public function record(RequestOrigin $origin): RequestOrigin
    {
        return $origin;
    }

    public function copy(RequestOrigin $oldRequestOriginCopy): RequestOrigin
    {
        return $oldRequestOriginCopy;
    }
}
