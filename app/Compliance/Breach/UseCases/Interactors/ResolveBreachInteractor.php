<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Compliance\Breach\Enums\BreachStage;
use App\Compliance\Breach\Events\BreachResolved;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\UseCases\Commands\ResolveBreachCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\Exceptions\BreachResolutionRefusedException;

class ResolveBreachInteractor
{
    public function execute(ResolveBreachCommand $resolveBreachCommand, User $platformAdmin, RequestOrigin $requestOrigin): BreachIncidentRecord
    {
        return DB::transaction(function () use ($resolveBreachCommand, $platformAdmin, $requestOrigin): BreachIncidentRecord {
            $breachIncident = BreachIncidentRecord::query()->whereKey($resolveBreachCommand->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $this->guardOpen($breachIncident);

            BreachIncident::retrieve($breachIncident->getKey())->resolve($resolveBreachCommand, $platformAdmin)->persist();

            $this->recordAudit($breachIncident, $platformAdmin, $requestOrigin);

            return BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breachIncident->getKey())->firstOrFail();
        });
    }

    protected function guardOpen(BreachIncidentRecord $breachIncident): void
    {
        match ($breachIncident->stage) {
            BreachStage::Resolved => throw BreachResolutionRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachResolutionRefusedException::falseAlarm(),
            BreachStage::Recorded, BreachStage::CompaniesNotified => null,
        };
    }

    protected function recordAudit(BreachIncidentRecord $breachIncident, User $platformAdmin, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->resolvedEventIdFor($breachIncident),
            'event_class' => 'BreachResolved',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breachIncident->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachResolved,
            'description' => 'Platform admin resolved a data breach incident.',
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

    protected function resolvedEventIdFor(BreachIncidentRecord $breachIncident): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachIncident->getKey())
            ->where('event_class', BreachResolved::class)
            ->sole()
            ->getKey();
    }
}
