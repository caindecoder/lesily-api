<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Foundation\AuditTrail\Values\RequestOrigin;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class ProtectedEventProperty extends ShouldBeStored
{
    protected RequestOrigin $origin;
}
