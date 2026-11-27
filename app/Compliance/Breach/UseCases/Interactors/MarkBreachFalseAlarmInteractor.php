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
    public function execute(MarkBreachFalseAlarmCommand $markBreachFalseAlarmCommand, User $platformAdmin, RequestOrigin $requestOrigin): BreachIncidentRecord
    {
        return DB::transaction(function () use ($markBreachFalseAlarmCommand, $platformAdmin, $requestOrigin): BreachIncidentRecord {
            $breachIncident = BreachIncidentRecord::query()->whereKey($markBreachFalseAlarmCommand->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $this->guardOpen($breachIncident);

            BreachIncident::retrieve($breachIncident->getKey())->markFalseAlarm($markBreachFalseAlarmCommand, $platformAdmin)->persist();

            $this->recordAudit($breachIncident, $platformAdmin, $requestOrigin);

            return BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breachIncident->getKey())->firstOrFail();
        });
    }

    protected function guardOpen(BreachIncidentRecord $breachIncident): void
    {
        match ($breachIncident->stage) {
            BreachStage::Resolved => throw BreachFalseAlarmRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachFalseAlarmRefusedException::alreadyMarked(),
            BreachStage::Recorded, BreachStage::CompaniesNotified => null,
        };
    }

    protected function recordAudit(BreachIncidentRecord $breachIncident, User $platformAdmin, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->markedEventIdFor($breachIncident),
            'event_class' => 'BreachMarkedFalseAlarm',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breachIncident->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachMarkedFalseAlarm,
            'description' => 'Platform admin marked a data breach incident a false alarm.',
            'actor_user_id' => $platformAdmin->getKey(),
            'actor_role_code' => AuditEntry::STAFF_ROLE_CODE,
            'actor_label' => $platformAdmin->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'metadata' => null,
            'severity' => 'critical',
            'recorded_at' => now(),
        ]);
    }

    protected function markedEventIdFor(BreachIncidentRecord $breachIncident): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachIncident->getKey())
            ->where('event_class', BreachMarkedFalseAlarm::class)
            ->sole()
            ->getKey();
    }
}
