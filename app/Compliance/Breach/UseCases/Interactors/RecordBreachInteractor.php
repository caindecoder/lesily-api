<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class RecordBreachInteractor
{
    public function execute(RecordBreachCommand $command, User $platformAdmin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($command, $platformAdmin, $context): BreachIncidentRecord {
            $breachId = Str::uuid7()->toString();

            BreachIncident::retrieve($breachId)
                ->record($command, $platformAdmin)
                ->persist();

            $breach = BreachIncidentRecord::query()
                ->with(['companies.company', 'amendments'])
                ->findOrFail($breachId);
            $this->recordAudit($breach, $platformAdmin, $context);

            return $breach;
        });
    }

    protected function recordAudit(BreachIncidentRecord $breach, User $platformAdmin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->recordedEventIdFor($breach),
            'event_class' => 'BreachRecorded',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breach->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachRecorded,
            'description' => 'Platform admin recorded a data breach.',
            'actor_user_id' => $platformAdmin->getKey(),
            'actor_role_code' => AuditEntry::STAFF_ROLE_CODE,
            'actor_label' => $platformAdmin->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'severity' => 'critical',
            'recorded_at' => now(),
        ]);
    }

    protected function recordedEventIdFor(BreachIncidentRecord $breach): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breach->getKey())
            ->where('event_class', BreachRecorded::class)
            ->sole()
            ->getKey();
    }
}
