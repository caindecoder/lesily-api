<?php

namespace App\Compliance\Breach\Projectors;

use Carbon\CarbonImmutable;
use App\Compliance\Breach\Enums\BreachStage;
use App\Compliance\Breach\Events\BreachAmended;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Entities\BreachAmendment;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
use App\Compliance\Breach\Events\BreachCompaniesNotified;
use App\Compliance\Breach\Events\BreachFiledWithRegulator;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class BreachIncidentProjector extends Projector
{
    public function onBreachRecorded(BreachRecorded $event): void
    {
        BreachIncidentRecord::query()->create([
            'id' => $event->breachId,
            'stage' => BreachStage::Recorded,
            'severity' => $event->severity,
            'description' => $event->description,
            'likely_consequences' => $event->likelyConsequences,
            'measures' => $event->measures,
            'date_discovered' => $event->dateDiscovered,
            'deadline' => $event->deadline,
            'recorded_at' => $event->recordedAt,
            'recorded_by' => $event->recordedByUserId,
        ]);

        foreach ($event->companies as $company) {
            $this->insertCompany($event, $company);
        }
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

    public function onBreachDeadlineMissed(BreachDeadlineMissed $event): void
    {
        BreachIncidentRecord::query()->whereKey($event->breachId)->update(['deadline_missed_at' => $event->missedAt]);
    }

    public function onBreachCompaniesNotified(BreachCompaniesNotified $event): void
    {
        BreachIncidentRecord::query()->whereKey($event->breachId)->update([
            'stage' => BreachStage::CompaniesNotified,
            'companies_notified_at' => $event->notifiedAt,
        ]);
    }

    public function onBreachAmended(BreachAmended $event): void
    {
        foreach ($event->changes as $change) {
            $this->applyChange($event, $change);

            BreachAmendment::query()->create([
                'breach_incident_id' => $event->breachId,
                'company_id' => $change['companyId'],
                'field' => $change['field'],
                'old_value' => $change['old'],
                'new_value' => $change['new'],
                'reason' => $event->reason,
                'amended_at' => $event->amendedAt,
                'amended_by' => $event->amendedByUserId,
            ]);
        }
    }

    /**
     * @param  array{field: string, companyId: ?string, old: mixed, new: mixed}  $change
     */
    protected function applyChange(BreachAmended $event, array $change): void
    {
        $incident = BreachIncidentRecord::query()->whereKey($event->breachId);
        $company = BreachIncidentCompany::query()->where('breach_incident_id', $event->breachId)->where('company_id', $change['companyId']);

        match (BreachAmendmentField::from($change['field'])) {
            BreachAmendmentField::Description => $incident->update(['description' => $change['new']]),
            BreachAmendmentField::LikelyConsequences => $incident->update(['likely_consequences' => $change['new']]),
            BreachAmendmentField::Measures => $incident->update(['measures' => $change['new']]),
            BreachAmendmentField::DateDiscovered => $incident->update(['date_discovered' => CarbonImmutable::parse($change['new'])]),
            BreachAmendmentField::Severity => $incident->update(['severity' => $change['new']]),
            BreachAmendmentField::DataTypes => $company->update(['data_types' => $change['new']]),
            BreachAmendmentField::EstimatedCount => $company->update(['estimated_count' => $change['new']]),
            BreachAmendmentField::CompanyAdded, BreachAmendmentField::CompanyRemoved => null,
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
