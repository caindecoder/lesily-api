<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarRequestRejected;
use App\Compliance\Dsar\UseCases\Audit\DsarOutcomeAudit;
use App\Compliance\Dsar\UseCases\Commands\RejectDsarRequestCommand;

class RejectDsarRequestInteractor
{
    public function __construct(private readonly DsarOutcomeAudit $audit) {}

    public function execute(RejectDsarRequestCommand $command): void
    {
        DB::transaction(function () use ($command): void {
            DsarRequest::retrieve($command->requestId)->reject($command)->persist();

            $this->audit->record(DsarRequestRecord::query()->findOrFail($command->requestId), DsarRequestRejected::class, AuditAction::DsarRejected);
        });
    }
}
