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
    public function __construct(private readonly DsarOutcomeAudit $dsarOutcomeAudit) {}

    public function execute(FulfilDsarRequestCommand $fulfilDsarRequestCommand): void
    {
        DB::transaction(function () use ($fulfilDsarRequestCommand): void {
            DsarRequest::retrieve($fulfilDsarRequestCommand->dsarRequest->getKey())->fulfil($fulfilDsarRequestCommand)->persist();

            $this->dsarOutcomeAudit->record(DsarRequestRecord::query()->whereKey($fulfilDsarRequestCommand->dsarRequest->getKey())->firstOrFail(), DsarRequestFulfilled::class, AuditAction::DsarFulfilled);
        });
    }
}
