<?php

namespace App\Compliance\Breach\Projectors;

use App\Compliance\Breach\Enums\BreachStage;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;
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
