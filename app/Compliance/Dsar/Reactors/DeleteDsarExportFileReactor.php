<?php

namespace App\Compliance\Dsar\Reactors;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarDownloadExpired;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Compliance\Dsar\UseCases\Jobs\DeleteDsarExportFileJob;

class DeleteDsarExportFileReactor extends Reactor implements ShouldQueue
{
    public function onDsarDownloadExpired(DsarDownloadExpired $event): void
    {
        $request = DsarRequestRecord::query()->findOrFail($event->aggregateRootUuid());

        dispatch(new DeleteDsarExportFileJob($request->exportFilePath()));
    }
}
