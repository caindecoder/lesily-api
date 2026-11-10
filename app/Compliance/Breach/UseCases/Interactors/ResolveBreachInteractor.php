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
    public function execute(ResolveBreachCommand $command, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($command, $admin, $context): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $this->guardOpen($breach);

            BreachIncident::retrieve($breach->getKey())->resolve($command, $admin)->persist();

            $this->recordAudit($breach, $admin, $context);

            return BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breach->getKey())->firstOrFail();
        });
    }

    protected function guardOpen(BreachIncidentRecord $breach): void
    {
        match ($breach->stage) {
            BreachStage::Resolved => throw BreachResolutionRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachResolutionRefusedException::falseAlarm(),
            BreachStage::Recorded, BreachStage::CompaniesNotified => null,
        };
    }

    protected function recordAudit(BreachIncidentRecord $breach, User $admin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->resolvedEventIdFor($breach),
            'event_class' => 'BreachResolved',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breach->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachResolved,
            'description' => 'Platform admin resolved a data breach incident.',
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'severity' => 'critical',
            'recorded_at' => now(),
        ]);
    }

    protected function resolvedEventIdFor(BreachIncidentRecord $breach): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breach->getKey())
            ->where('event_class', BreachResolved::class)
            ->sole()
            ->getKey();
    }
}
