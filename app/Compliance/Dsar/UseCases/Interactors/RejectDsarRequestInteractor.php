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
    public function __construct(private readonly DsarOutcomeAudit $dsarOutcomeAudit) {}

    public function execute(RejectDsarRequestCommand $rejectDsarRequestCommand): void
    {
        DB::transaction(function () use ($rejectDsarRequestCommand): void {
            DsarRequest::retrieve($rejectDsarRequestCommand->dsarRequest->getKey())->reject($rejectDsarRequestCommand)->persist();

            $this->dsarOutcomeAudit->record(DsarRequestRecord::query()->whereKey($rejectDsarRequestCommand->dsarRequest->getKey())->firstOrFail(), DsarRequestRejected::class, AuditAction::DsarRejected);
        });
    }
}
