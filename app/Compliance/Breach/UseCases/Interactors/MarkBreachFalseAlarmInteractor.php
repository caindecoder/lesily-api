<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Events\BreachMarkedFalseAlarm;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\Exceptions\BreachFalseAlarmRefusedException;
use App\Compliance\Breach\UseCases\Commands\MarkBreachFalseAlarmCommand;

class MarkBreachFalseAlarmInteractor
{
    public function execute(MarkBreachFalseAlarmCommand $command, User $platformAdmin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($command, $platformAdmin, $context): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $this->guardOpen($breach);

            BreachIncident::retrieve($breach->getKey())->markFalseAlarm($command, $platformAdmin)->persist();

            $this->recordAudit($breach, $platformAdmin, $context);

            return BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breach->getKey())->firstOrFail();
        });
    }

    protected function guardOpen(BreachIncidentRecord $breach): void
    {
        match ($breach->stage) {
            BreachStage::Resolved => throw BreachFalseAlarmRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachFalseAlarmRefusedException::alreadyMarked(),
            BreachStage::Recorded, BreachStage::CompaniesNotified => null,
        };
    }

    protected function recordAudit(BreachIncidentRecord $breach, User $platformAdmin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->markedEventIdFor($breach),
            'event_class' => 'BreachMarkedFalseAlarm',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breach->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachMarkedFalseAlarm,
            'description' => 'Platform admin marked a data breach incident a false alarm.',
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

    protected function markedEventIdFor(BreachIncidentRecord $breach): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breach->getKey())
            ->where('event_class', BreachMarkedFalseAlarm::class)
            ->sole()
            ->getKey();
    }
}
