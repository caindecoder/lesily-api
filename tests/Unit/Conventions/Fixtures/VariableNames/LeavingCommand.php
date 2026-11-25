<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Spatie\LaravelData\Data;
use App\Foundation\AuditTrail\Values\RequestOrigin;

final class LeavingCommand extends Data
{
    public function __construct(public RequestOrigin $origin) {}
}
