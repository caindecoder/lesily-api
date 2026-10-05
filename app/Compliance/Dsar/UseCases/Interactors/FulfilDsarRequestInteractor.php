<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarRequestFulfilled;
use App\Compliance\Dsar\UseCases\Commands\FulfilDsarRequestCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class FulfilDsarRequestInteractor
{
    public function execute(FulfilDsarRequestCommand $command): void
    {
        DB::transaction(function () use ($command): void {
            DsarRequest::retrieve($command->requestId)->fulfil($command)->persist();

            $this->recordFulfilled(DsarRequestRecord::query()->findOrFail($command->requestId));
        });
    }

    private function recordFulfilled(DsarRequestRecord $request): void
    {
        AuditEntry::query()->create([
            'company_id' => $request->company_id,
            'stored_event_id' => $this->fulfilledEventIdFor($request),
            'event_class' => 'DsarRequestFulfilled',
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => 'dsar_fulfilled',
            'description' => 'DSAR fulfilled — for: '.$request->subject->name,
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

    private function fulfilledEventIdFor(DsarRequestRecord $request): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestFulfilled::class)
            ->sole()
            ->id;
    }
}
