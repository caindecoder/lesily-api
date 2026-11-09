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

    public function onBreachRecorded(BreachRecorded $event): void
    {
        foreach ($event->companies as $company) {
            $this->insertCompany($event, $company);
        }
    }

    public function onBreachCompanyAdded(BreachCompanyAdded $event): void
    {
        BreachIncidentCompany::query()->create([
            'breach_incident_id' => $event->breachId,
            'company_id' => $event->companyId,
            'data_types' => $event->dataTypes,
            'estimated_count' => $event->estimatedCount,
            'added_at' => $event->addedAt,
            'added_by' => $event->addedByUserId,
            'added_reason' => $event->reason,
        ]);
    }

    public function onBreachCompanyNotified(BreachCompanyNotified $event): void
    {
        BreachIncidentCompany::query()
            ->where('breach_incident_id', $event->breachId)
            ->where('company_id', $event->companyId)
            ->update(['notified_at' => $event->notifiedAt]);
    }

    public function onBreachFilingRequested(BreachFilingRequested $event): void
    {
        BreachIncidentCompany::query()
            ->where('breach_incident_id', $event->breachId)
            ->where('company_id', $event->companyId)
            ->update(['filing_requested_at' => $event->requestedAt, 'filing_requested_by' => $event->requestedByUserId]);
    }

    public function onBreachFiledWithRegulator(BreachFiledWithRegulator $event): void
    {
        BreachIncidentCompany::query()
            ->where('breach_incident_id', $event->breachId)
            ->where('company_id', $event->companyId)
            ->update(['filed_at' => $event->filedAt, 'filed_by' => $event->filedByUserId, 'filed_body_hash' => $event->bodyHash]);
    }

    public function onBreachAmended(BreachAmended $event): void
    {
        foreach ($event->changes as $change) {
            $this->applyChange($event, $change);
        }
    }

    /**
     * @param  array{field: string, companyId: ?string, old: mixed, new: mixed}  $change
     */
    protected function applyChange(BreachAmended $event, array $change): void
    {
        $company = BreachIncidentCompany::query()->where('breach_incident_id', $event->breachId)->where('company_id', $change['companyId']);

        match (BreachAmendmentField::from($change['field'])) {
            BreachAmendmentField::DataTypes => $company->update(['data_types' => $change['new']]),
            BreachAmendmentField::EstimatedCount => $company->update(['estimated_count' => $change['new']]),
            BreachAmendmentField::CompanyAdded => BreachIncidentCompany::query()->create([
                'breach_incident_id' => $event->breachId,
                'company_id' => $change['companyId'],
                'data_types' => $change['new']['dataTypes'],
                'estimated_count' => $change['new']['estimatedCount'],
                'added_at' => $event->amendedAt,
                'added_by' => $event->amendedByUserId,
            ]),
            BreachAmendmentField::CompanyRemoved => $company->delete(),
            default => null,
        };
    }

    /**
     * @param  array{companyId: string, dataTypes: list<string>, estimatedCount: ?int}  $company
     */
    protected function insertCompany(BreachRecorded $event, array $company): void
    {
        BreachIncidentCompany::query()->create([
            'breach_incident_id' => $event->breachId,
            'company_id' => $company['companyId'],
            'data_types' => $company['dataTypes'],
            'estimated_count' => $company['estimatedCount'],
            'added_at' => $event->recordedAt,
            'added_by' => $event->recordedByUserId,
        ]);
    }
}
