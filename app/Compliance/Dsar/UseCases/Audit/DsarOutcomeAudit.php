<?php

namespace App\Compliance\Dsar\UseCases\Audit;

use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class DsarOutcomeAudit
{
    /**
     * One audit entry for the export job's outcome on $request — "DSAR
     * <outcome> — for: <subject>" — linked to its stored $eventClass, with no
     * actor: the job is a system process (ERD-1-11).
     *
     * @param  class-string  $eventClass
     */
    public function record(DsarRequestRecord $request, string $eventClass, string $outcome): void
    {
        AuditEntry::query()->create([
            'company_id' => $request->company_id,
            'stored_event_id' => $this->storedEventIdOf($request, $eventClass),
            'event_class' => class_basename($eventClass),
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'subject_label' => null,
            'action' => 'dsar_'.$outcome,
            'description' => 'DSAR '.$outcome.' — for: '.$request->subject->name,
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

    /**
     * @param  class-string  $eventClass
     */
    private function storedEventIdOf(DsarRequestRecord $request, string $eventClass): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', $eventClass)
            ->sole()
            ->id;
    }
}
