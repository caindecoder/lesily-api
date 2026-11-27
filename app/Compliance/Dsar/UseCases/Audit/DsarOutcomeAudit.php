<?php

namespace App\Compliance\Dsar\UseCases\Audit;

use Illuminate\Support\Str;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class DsarOutcomeAudit
{
    /**
     * One audit entry for a system process's outcome on $dsarRequest — the
     * export job's or a daily scan's: "DSAR <outcome> — for: <subject>",
     * linked to its stored $eventClass, with no actor (ERD-1-11).
     *
     * @param  class-string  $eventClass
     */
    public function record(DsarRequestRecord $dsarRequest, string $eventClass, AuditAction $auditAction): void
    {
        AuditEntry::query()->create([
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => $this->storedEventIdOf($dsarRequest, $eventClass),
            'event_class' => class_basename($eventClass),
            'subject_type' => AuditSubjectType::DsarRequest,
            'subject_id' => $dsarRequest->getKey(),
            'subject_label' => null,
            'action' => $auditAction,
            'description' => 'DSAR '.str_replace('_', ' ', Str::after($auditAction->value, 'dsar_')).' — for: '.$dsarRequest->subject->name,
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
    protected function storedEventIdOf(DsarRequestRecord $dsarRequest, string $eventClass): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', $eventClass)
            ->sole()
            ->getKey();
    }
}
