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
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Foundation\AuditTrail\UseCases\Queries\MemberRoleCode;
use App\Compliance\Breach\Exceptions\BreachFilingNotOpenException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;

class RequestBreachFilingInteractor
{
    public function __construct(protected readonly MemberRoleCode $memberRoleCode) {}

    public function execute(RequestBreachFilingCommand $requestBreachFilingCommand, User $owner, RequestOrigin $requestOrigin): BreachIncidentCompany
    {
        return DB::transaction(function () use ($requestBreachFilingCommand, $owner, $requestOrigin): BreachIncidentCompany {
            $breachIncident = BreachIncidentRecord::query()->whereKey($requestBreachFilingCommand->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $breachIncidentCompany = BreachIncidentCompany::query()
                ->where('breach_incident_id', $breachIncident->getKey())
                ->whereCompany($requestBreachFilingCommand->company)
                ->whereNotified()
                ->first()
                ?? throw new ModelNotFoundException;

            $this->guardFilingOpen($breachIncident, $breachIncidentCompany);

            BreachIncident::retrieve($breachIncident->getKey())->requestFiling($requestBreachFilingCommand, $owner)->persist();

            $this->recordAudit($breachIncidentCompany, $owner, $requestOrigin);

            return BreachIncidentCompany::query()->whereKey($breachIncidentCompany->getKey())->firstOrFail();
        });
    }

    protected function recordAudit(BreachIncidentCompany $breachIncidentCompany, User $owner, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'company_id' => $breachIncidentCompany->company_id,
            'stored_event_id' => $this->requestedEventIdFor($breachIncidentCompany),
            'event_class' => 'BreachFilingRequested',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breachIncidentCompany->breach_incident_id,
            'subject_label' => null,
            'action' => AuditAction::BreachFilingRequested,
            'description' => 'Owner asked Lesily to file a data breach with the Data Protection Authority.',
            'actor_user_id' => $owner->getKey(),
            'actor_role_code' => $this->memberRoleCode->of($owner, $breachIncidentCompany->company_id),
            'actor_label' => $owner->name,
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'metadata' => null,
            'severity' => 'critical',
            'recorded_at' => now(),
        ]);
    }

    protected function requestedEventIdFor(BreachIncidentCompany $breachIncidentCompany): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachIncidentCompany->breach_incident_id)
            ->where('event_class', BreachFilingRequested::class)
            ->where('event_properties->companyId', $breachIncidentCompany->company_id)
            ->sole()
            ->getKey();
    }

    protected function guardFilingOpen(BreachIncidentRecord $breachIncident, BreachIncidentCompany $breachIncidentCompany): void
    {
        if ($breachIncident->stage === BreachStage::Resolved) {
            throw BreachFilingNotOpenException::resolved();
        }

        if ($breachIncidentCompany->filed_at !== null) {
            throw BreachFilingNotOpenException::alreadyFiled();
        }

        if ($breachIncidentCompany->filing_requested_at !== null) {
            throw BreachFilingNotOpenException::alreadyRequested();
        }
    }
}
