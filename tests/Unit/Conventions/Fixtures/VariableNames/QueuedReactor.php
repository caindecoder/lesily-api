<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

final class QueuedReactor extends Reactor implements ShouldQueue
{
    public function __construct(protected RequestOrigin $origin) {}
}
