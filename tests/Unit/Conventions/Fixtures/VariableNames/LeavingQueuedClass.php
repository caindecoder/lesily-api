<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Foundation\AuditTrail\Values\RequestOrigin;

final class LeavingQueuedClass implements ShouldQueue
{
    public function __construct(protected RequestOrigin $origin) {}
}
