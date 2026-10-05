<?php

namespace App\Compliance\Dsar\Reactors;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Compliance\Dsar\UseCases\Jobs\BuildDsarExportJob;

class QueueDsarExportReactor extends Reactor implements ShouldQueue
{
    public function onDsarRequestSubmitted(DsarRequestSubmitted $event): void
    {
        dispatch(new BuildDsarExportJob($event->requestId, $event->companyId, $event->subjectUserId));
    }
}
