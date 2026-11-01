<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\AuditTrail\Enums\AuditAction;
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
            DsarRequest::retrieve($command->dsarRequest->getKey())->fulfil($command)->persist();

            $this->audit->record(DsarRequestRecord::query()->whereKey($command->dsarRequest->getKey())->firstOrFail(), DsarRequestFulfilled::class, AuditAction::DsarFulfilled);
        });
    }
}
