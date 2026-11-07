<?php

namespace App\Compliance\Breach\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Compliance\Breach\Values\RegulatorFiling;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Compliance\Breach\Aggregates\BreachIncident;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\Mail\BreachRegulatorNoticeMail;
use App\Compliance\Breach\Events\BreachFiledWithRegulator;
use App\Compliance\Breach\Exceptions\BreachFilingNotOpenException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Breach\UseCases\Renderers\BreachCompanyPartRenderer;
use App\Compliance\Breach\UseCases\Commands\FileBreachWithRegulatorCommand;
use App\Compliance\Breach\Exceptions\RegulatorAddressNotConfiguredException;

class FileBreachWithRegulatorInteractor
{
    public function __construct(protected readonly BreachCompanyPartRenderer $renderer) {}

    public function execute(FileBreachWithRegulatorCommand $command, User $admin, RequestOrigin $context): BreachIncidentRecord
    {
        $regulatorAddress = Config::string('platform.regulator_address');

        if (blank($regulatorAddress)) {
            throw RegulatorAddressNotConfiguredException::forFiling();
        }

        return DB::transaction(function () use ($command, $admin, $context, $regulatorAddress): BreachIncidentRecord {
            $breach = BreachIncidentRecord::query()->whereKey($command->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $filing = BreachIncidentCompany::query()
                ->where('breach_incident_id', $breach->getKey())
                ->whereCompany($command->company)
                ->first()
                ?? throw new ModelNotFoundException;

            $this->guardFilingOpen($breach, $filing);
            $regulatorFiling = $this->regulatorFilingFor($breach, $filing, $regulatorAddress);

            BreachIncident::retrieve($breach->getKey())->fileWithRegulator($command, $admin, $regulatorFiling)->persist();

            $this->recordAudit($filing, $admin, $context);

            return BreachIncidentRecord::query()->with('companies.company')->whereKey($breach->getKey())->firstOrFail();
        });
    }

    protected function regulatorFilingFor(BreachIncidentRecord $breach, BreachIncidentCompany $filing, string $regulatorAddress): RegulatorFiling
    {
        $companyName = $filing->company->name;
        $part = $this->renderer->render($breach, $filing);

        return new RegulatorFiling(
            companyName: $companyName,
            regulatorAddress: $regulatorAddress,
            part: $part,
            bodyHash: hash('sha256', (new BreachRegulatorNoticeMail($companyName, $part))->render()),
        );
    }

    protected function recordAudit(BreachIncidentCompany $filing, User $admin, RequestOrigin $context): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->filedEventIdFor($filing),
            'event_class' => 'BreachFiledWithRegulator',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $filing->breach_incident_id,
            'subject_label' => null,
            'action' => AuditAction::BreachFiledWithRegulator,
            'description' => 'Platform admin filed a data breach with the Data Protection Authority for a company that asked.',
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

    protected function filedEventIdFor(BreachIncidentCompany $filing): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $filing->breach_incident_id)
            ->where('event_class', BreachFiledWithRegulator::class)
            ->where('event_properties->companyId', $filing->company_id)
            ->sole()
            ->getKey();
    }

    protected function guardFilingOpen(BreachIncidentRecord $breach, BreachIncidentCompany $filing): void
    {
        match ($breach->stage) {
            BreachStage::Recorded => throw BreachFilingNotOpenException::notYetNotified(),
            BreachStage::Resolved => throw BreachFilingNotOpenException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachFilingNotOpenException::falseAlarm(),
            BreachStage::CompaniesNotified => null,
        };

        if ($filing->filed_at !== null) {
            throw BreachFilingNotOpenException::alreadyFiled();
        }
    }
}
