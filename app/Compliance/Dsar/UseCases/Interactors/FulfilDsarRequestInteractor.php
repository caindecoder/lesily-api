<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarRequestFulfilled;
use App\Compliance\Dsar\UseCases\Audit\DsarOutcomeAudit;
use App\Compliance\Dsar\UseCases\Commands\FulfilDsarRequestCommand;

class FulfilDsarRequestInteractor
{
    public function __construct(private readonly DsarOutcomeAudit $audit) {}

    public function execute(FulfilDsarRequestCommand $command): void
    {
        DB::transaction(function () use ($command): void {
            DsarRequest::retrieve($command->requestId)->fulfil($command)->persist();

            $this->audit->record(DsarRequestRecord::query()->findOrFail($command->requestId), DsarRequestFulfilled::class, 'fulfilled');
        });
    }
}
