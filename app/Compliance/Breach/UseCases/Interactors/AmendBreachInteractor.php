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
    public function execute(AmendBreachCommand $command, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        return DB::transaction(function () use ($command, $admin, $context): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $this->guardOpen($breach);
            $this->guardDiscoveredBeforeRecording($breach, $command);

            BreachIncident::retrieve($breach->getKey())->amend($command, $admin)->persist();

            $this->recordAudit($breach, $admin, $context);

            return BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breach->getKey())->firstOrFail();
        });
    }

    protected function guardDiscoveredBeforeRecording(BreachIncidentRecord $breach, AmendBreachCommand $command): void
    {
        if ($command->dateDiscovered?->greaterThan($breach->recorded_at)) {
            throw ValidationException::withMessages(['dateDiscovered' => 'The date discovered cannot be later than when the incident was recorded.']);
        }
    }

    protected function recordAudit(BreachIncidentRecord $breach, User $admin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->amendedEventIdFor($breach),
            'event_class' => 'BreachAmended',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breach->getKey(),
            'subject_label' => null,
            'action' => AuditAction::BreachAmended,
            'description' => 'Platform admin corrected a data breach record.',
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

    protected function amendedEventIdFor(BreachIncidentRecord $breach): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breach->getKey())
            ->where('event_class', BreachAmended::class)
            ->latest('id')
            ->firstOrFail()
            ->getKey();
    }

    protected function guardOpen(BreachIncidentRecord $breach): void
    {
        match ($breach->stage) {
            BreachStage::Resolved => throw BreachAmendmentRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachAmendmentRefusedException::falseAlarm(),
            BreachStage::Recorded, BreachStage::CompaniesNotified => null,
        };
    }
}
