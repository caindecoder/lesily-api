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
    public function __construct(protected readonly BreachCompanyPartRenderer $breachCompanyPartRenderer) {}

    public function execute(FileBreachWithRegulatorCommand $fileBreachWithRegulatorCommand, User $platformAdmin, RequestOrigin $requestOrigin): BreachIncidentRecord
    {
        $regulatorAddress = Config::string('platform.regulator_address');

        if (blank($regulatorAddress)) {
            throw RegulatorAddressNotConfiguredException::forFiling();
        }

        return DB::transaction(function () use ($fileBreachWithRegulatorCommand, $platformAdmin, $requestOrigin, $regulatorAddress): BreachIncidentRecord {
            $breachIncident = BreachIncidentRecord::query()->whereKey($fileBreachWithRegulatorCommand->breachId)->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;

            $breachIncidentCompany = BreachIncidentCompany::query()
                ->where('breach_incident_id', $breachIncident->getKey())
                ->whereCompany($fileBreachWithRegulatorCommand->company)
                ->first()
                ?? throw new ModelNotFoundException;

            $this->guardFilingOpen($breachIncident, $breachIncidentCompany);
            $regulatorFiling = $this->regulatorFilingFor($breachIncident, $breachIncidentCompany, $regulatorAddress);

            BreachIncident::retrieve($breachIncident->getKey())->fileWithRegulator($fileBreachWithRegulatorCommand, $platformAdmin, $regulatorFiling)->persist();

            $this->recordAudit($breachIncidentCompany, $platformAdmin, $requestOrigin);

            return BreachIncidentRecord::query()->with(['companies.company', 'amendments'])->whereKey($breachIncident->getKey())->firstOrFail();
        });
    }

    protected function regulatorFilingFor(BreachIncidentRecord $breachIncident, BreachIncidentCompany $breachIncidentCompany, string $regulatorAddress): RegulatorFiling
    {
        $companyName = $breachIncidentCompany->company->name;
        $part = $this->breachCompanyPartRenderer->render($breachIncident, $breachIncidentCompany);

        return new RegulatorFiling(
            companyName: $companyName,
            regulatorAddress: $regulatorAddress,
            part: $part,
            bodyHash: hash('sha256', (new BreachRegulatorNoticeMail($companyName, $part))->noticeText()),
        );
    }

    protected function recordAudit(BreachIncidentCompany $breachIncidentCompany, User $platformAdmin, RequestOrigin $requestOrigin): void
    {
        AuditEntry::query()->create([
            'stored_event_id' => $this->filedEventIdFor($breachIncidentCompany),
            'event_class' => 'BreachFiledWithRegulator',
            'subject_type' => AuditSubjectType::BreachIncident,
            'subject_id' => $breachIncidentCompany->breach_incident_id,
            'subject_label' => null,
            'action' => AuditAction::BreachFiledWithRegulator,
            'description' => 'Platform admin filed a data breach with the Data Protection Authority for a company that asked.',
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

    protected function filedEventIdFor(BreachIncidentCompany $breachIncidentCompany): int
    {
        return LesilyStoredEvent::query()
            ->where('aggregate_uuid', $breachIncidentCompany->breach_incident_id)
            ->where('event_class', BreachFiledWithRegulator::class)
            ->where('event_properties->companyId', $breachIncidentCompany->company_id)
            ->sole()
            ->getKey();
    }

    protected function guardFilingOpen(BreachIncidentRecord $breachIncident, BreachIncidentCompany $breachIncidentCompany): void
    {
        match ($breachIncident->stage) {
            BreachStage::Recorded => throw BreachFilingNotOpenException::filingBeforeTheNotices(),
            BreachStage::Resolved => throw BreachFilingNotOpenException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachFilingNotOpenException::falseAlarm(),
            BreachStage::CompaniesNotified => null,
        };

        if ($breachIncidentCompany->filed_at !== null) {
            throw BreachFilingNotOpenException::alreadyFiled();
        }
    }
}
