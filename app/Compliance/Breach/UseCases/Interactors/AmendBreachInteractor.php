<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Compliance\Breach\Enums\BreachStage;
use Illuminate\Validation\ValidationException;
use App\Compliance\Breach\Events\BreachAmended;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\UseCases\Commands\AmendBreachCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\Exceptions\BreachAmendmentRefusedException;

class AmendBreachInteractor
{
    public function execute(AmendBreachCommand $amendBreachCommand, User $platformAdmin, RequestOrigin $requestOrigin): BreachIncidentRecord
    {
        return DB::transaction(function () use ($amendBreachCommand, $platformAdmin, $requestOrigin): BreachIncidentRecord {
            $breachIncident = BreachIncidentRecord::query()->whereKey($amendBreachCommand->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $this->guardOpen($breachIncident);
            $this->guardDiscoveredBeforeRecording($breachIncident, $amendBreachCommand);

            BreachIncident::retrieve($breachIncident->getKey())->amend($amendBreachCommand, $platformAdmin)->persist();

            $this->recordAudit($breachIncident, $platformAdmin, $requestOrigin);

            return BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breachIncident->getKey())->firstOrFail();
        });
    }

    protected function guardDiscoveredBeforeRecording(BreachIncidentRecord $breachIncident, AmendBreachCommand $amendBreachCommand): void
    {
        if ($amendBreachCommand->dateDiscovered?->greaterThan($breachIncident->recorded_at)) {
            throw ValidationException::withMessages(['dateDiscovered' => 'The date discovered cannot be later than when the incident was recorded.']);
        }
    }

    protected function recordAudit(BreachIncidentRecord $breachIncident, User $platformAdmin, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->amendedEventIdFor($breachIncident),
            'event_class' => 'BreachAmended',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breachIncident->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachAmended,
            'description' => 'Platform admin corrected a data breach record.',
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

    protected function amendedEventIdFor(BreachIncidentRecord $breachIncident): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachIncident->getKey())
            ->where('event_class', BreachAmended::class)
            ->latest('id')
            ->firstOrFail()
            ->getKey();
    }

    protected function guardOpen(BreachIncidentRecord $breachIncident): void
    {
        match ($breachIncident->stage) {
            BreachStage::Resolved => throw BreachAmendmentRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachAmendmentRefusedException::falseAlarm(),
            BreachStage::Recorded, BreachStage::CompaniesNotified => null,
        };
    }
}
