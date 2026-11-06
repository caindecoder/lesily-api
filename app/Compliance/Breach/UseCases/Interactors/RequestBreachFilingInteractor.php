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
use App\Compliance\Dsar\UseCases\Queries\MemberRoleCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\Exceptions\BreachFilingNotOpenException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;

class RequestBreachFilingInteractor
{
    public function __construct(protected readonly MemberRoleCode $memberRoleCode) {}

    public function execute(RequestBreachFilingCommand $command, User $owner, RequestOrigin $context): BreachIncidentCompany
    {
        return DB::transaction(function () use ($command, $owner, $context): BreachIncidentCompany {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $notice = BreachIncidentCompany::query()
                ->where('breach_incident_id', $breach->getKey())
                ->whereCompany($command->company)
                ->whereNotified()
                ->first()
                ?? throw new ModelNotFoundException;

            $this->guardFilingOpen($breach, $notice);

            BreachIncident::retrieve($breach->getKey())->requestFiling($command, $owner)->persist();

            $this->recordAudit($notice, $owner, $context);

            return BreachIncidentCompany::query()->with(['breach', 'company'])->whereKey($notice->getKey())->firstOrFail();
        });
    }

    protected function recordAudit(BreachIncidentCompany $notice, User $owner, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $notice->company_id,
            'stored_event_id' => $this->requestedEventIdFor($notice),
            'event_class' => 'BreachFilingRequested',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $notice->breach_incident_id,
            'subject_label' => null,
            'action' => AuditAction::BreachFilingRequested,
            'description' => 'Owner asked Lesily to file a data breach with the Data Protection Authority.',
            'actor_user_id' => $owner->getKey(),
            'actor_role_code' => $this->memberRoleCode->of($owner, $notice->company_id),
            'actor_label' => $owner->name,
            'ip_address' => $context->ip,
            'user_agent' => $context->boundedUserAgent(),
            'request_id' => $context->boundedRequestId(),
            'metadata' => null,
            'severity' => 'critical',
            'recorded_at' => now(),
        ]);
    }

    protected function requestedEventIdFor(BreachIncidentCompany $notice): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $notice->breach_incident_id)
            ->where('event_class', BreachFilingRequested::class)
            ->where('event_properties->companyId', $notice->company_id)
            ->sole()
            ->getKey();
    }

    protected function guardFilingOpen(BreachIncidentRecord $breach, BreachIncidentCompany $notice): void
    {
        if ($breach->stage === BreachStage::Resolved) {
            throw BreachFilingNotOpenException::resolved();
        }

        if ($notice->filed_at !== null) {
            throw BreachFilingNotOpenException::alreadyFiled();
        }

        if ($notice->filing_requested_at !== null) {
            throw BreachFilingNotOpenException::alreadyRequested();
        }
    }
}
