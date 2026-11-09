<?php

namespace App\Compliance\Breach\Projectors;

use App\Compliance\Breach\Events\BreachAmended;
use App\Compliance\Breach\Entities\BreachAmendment;
use App\Compliance\Breach\Events\BreachCompanyAdded;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class BreachAmendmentProjector extends Projector
{
    public function onBreachAmended(BreachAmended $event): void
    {
        foreach ($event->changes as $change) {
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

    public function onBreachCompanyAdded(BreachCompanyAdded $event): void
    {
        BreachAmendment::query()->create([
            'breach_incident_id' => $event->breachId,
            'company_id' => $event->companyId,
            'field' => BreachAmendmentField::LateCompanyAdded,
            'old_value' => null,
            'new_value' => ['dataTypes' => $event->dataTypes, 'estimatedCount' => $event->estimatedCount],
            'reason' => $event->reason,
            'amended_at' => $event->addedAt,
            'amended_by' => $event->addedByUserId,
        ]);
    }
}
