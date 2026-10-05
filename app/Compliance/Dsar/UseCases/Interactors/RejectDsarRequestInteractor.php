<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarRequestRejected;
use App\Compliance\Dsar\UseCases\Commands\RejectDsarRequestCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class RejectDsarRequestInteractor
{
    public function execute(RejectDsarRequestCommand $command): void
    {
        DB::transaction(function () use ($command): void {
            DsarRequest::retrieve($command->requestId)->reject($command)->persist();

            $this->recordRejected(DsarRequestRecord::query()->findOrFail($command->requestId));
        });
    }

    private function recordRejected(DsarRequestRecord $request): void
    {
        AuditEntry::query()->create([
            'company_id' => $request->company_id,
            'stored_event_id' => $this->rejectedEventIdFor($request),
            'event_class' => 'DsarRequestRejected',
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => 'dsar_rejected',
            'description' => 'DSAR rejected — for: '.$request->subject->name,
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }

    private function rejectedEventIdFor(DsarRequestRecord $request): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestRejected::class)
            ->sole()
            ->id;
    }
}
