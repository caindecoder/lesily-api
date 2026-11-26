<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

final class QueuedProjector extends Projector implements ShouldQueue
{
    public function __construct(protected RequestOrigin $origin) {}
}
