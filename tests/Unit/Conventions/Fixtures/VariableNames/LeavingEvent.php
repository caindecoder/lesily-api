<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Foundation\AuditTrail\Values\RequestOrigin;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class LeavingEvent extends ShouldBeStored
{
    public function __construct(public RequestOrigin $origin) {}
}
