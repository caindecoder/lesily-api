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
    public function execute(RecordBreachCommand $command, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($command, $admin, $context): BreachIncidentRecord {
            $breachId = Str::uuid7()->toString();

            BreachIncident::retrieve($breachId)
                ->record($command, $admin)
                ->persist();

            $breach = BreachIncidentRecord::query()
                ->with('companies.company')
                ->findOrFail($breachId);
            $this->recordAudit($breach, $admin, $context);

            return $breach;
        });
    }

    protected function recordAudit(BreachIncidentRecord $breach, User $admin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'company_id' => null,
            'stored_event_id' => $this->recordedEventIdFor($breach),
            'event_class' => 'BreachRecorded',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breach->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachRecorded,
            'description' => 'Platform admin recorded a data breach.',
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
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
