<?php

namespace App\Compliance\Breach\Projectors;

use App\Compliance\Breach\Events\BreachAmended;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Events\BreachCompanyAdded;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\Events\BreachFiledWithRegulator;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class BreachIncidentCompanyProjector extends Projector
{
    public int $weight = 1;

    public function onBreachRecorded(BreachRecorded $breachRecorded): void
    {
        foreach ($breachRecorded->companies as $company) {
            $this->insertCompany($breachRecorded, $company);
        }
    }

    public function onBreachCompanyAdded(BreachCompanyAdded $breachCompanyAdded): void
    {
        BreachIncidentCompany::query()->create([
            'breach_incident_id' => $breachCompanyAdded->breachId,
            'company_id' => $breachCompanyAdded->companyId,
            'data_types' => $breachCompanyAdded->dataTypes,
            'estimated_count' => $breachCompanyAdded->estimatedCount,
            'added_at' => $breachCompanyAdded->addedAt,
            'added_by' => $breachCompanyAdded->addedByUserId,
            'added_reason' => $breachCompanyAdded->reason,
        ]);
    }

    public function onBreachCompanyNotified(BreachCompanyNotified $breachCompanyNotified): void
    {
        BreachIncidentCompany::query()
            ->where('breach_incident_id', $breachCompanyNotified->breachId)
            ->where('company_id', $breachCompanyNotified->companyId)
            ->update(['notified_at' => $breachCompanyNotified->notifiedAt]);
    }

    public function onBreachFilingRequested(BreachFilingRequested $breachFilingRequested): void
    {
        BreachIncidentCompany::query()
            ->where('breach_incident_id', $breachFilingRequested->breachId)
            ->where('company_id', $breachFilingRequested->companyId)
            ->update(['filing_requested_at' => $breachFilingRequested->requestedAt, 'filing_requested_by' => $breachFilingRequested->requestedByUserId]);
    }

    public function onBreachFiledWithRegulator(BreachFiledWithRegulator $breachFiledWithRegulator): void
    {
        BreachIncidentCompany::query()
            ->where('breach_incident_id', $breachFiledWithRegulator->breachId)
            ->where('company_id', $breachFiledWithRegulator->companyId)
            ->update(['filed_at' => $breachFiledWithRegulator->filedAt, 'filed_by' => $breachFiledWithRegulator->filedByUserId, 'filed_body_hash' => $breachFiledWithRegulator->bodyHash]);
    }

    public function onBreachAmended(BreachAmended $breachAmended): void
    {
        foreach ($breachAmended->changes as $change) {
            $this->applyChange($breachAmended, $change);
        }
    }

    /**
     * @param  array{field: string, companyId: ?string, old: mixed, new: mixed}  $change
     */
    protected function applyChange(BreachAmended $breachAmended, array $change): void
    {
        $breachIncidentCompanies = BreachIncidentCompany::query()->where('breach_incident_id', $breachAmended->breachId)->where('company_id', $change['companyId']);

        match (BreachAmendmentField::from($change['field'])) {
            BreachAmendmentField::DataTypes => $breachIncidentCompanies->update(['data_types' => $change['new']]),
            BreachAmendmentField::EstimatedCount => $breachIncidentCompanies->update(['estimated_count' => $change['new']]),
            BreachAmendmentField::CompanyAdded => BreachIncidentCompany::query()->create([
                'breach_incident_id' => $breachAmended->breachId,
                'company_id' => $change['companyId'],
                'data_types' => $change['new']['dataTypes'],
                'estimated_count' => $change['new']['estimatedCount'],
                'added_at' => $breachAmended->amendedAt,
                'added_by' => $breachAmended->amendedByUserId,
            ]),
            BreachAmendmentField::CompanyRemoved => $breachIncidentCompanies->delete(),
            default => null,
        };
    }

    /**
     * @param  array{companyId: string, dataTypes: list<string>, estimatedCount: ?int}  $company
     */
    protected function insertCompany(BreachRecorded $breachRecorded, array $company): void
    {
        BreachIncidentCompany::query()->create([
            'breach_incident_id' => $breachRecorded->breachId,
            'company_id' => $company['companyId'],
            'data_types' => $company['dataTypes'],
            'estimated_count' => $company['estimatedCount'],
            'added_at' => $breachRecorded->recordedAt,
            'added_by' => $breachRecorded->recordedByUserId,
        ]);
    }
}
