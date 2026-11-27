<?php

namespace App\Compliance\Breach\Projectors;

use App\Compliance\Breach\Events\BreachAmended;
use App\Compliance\Breach\Entities\BreachAmendment;
use App\Compliance\Breach\Events\BreachCompanyAdded;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class BreachAmendmentProjector extends Projector
{
    public function onBreachAmended(BreachAmended $breachAmended): void
    {
        foreach ($breachAmended->changes as $change) {
            BreachAmendment::query()->create([
                'breach_incident_id' => $breachAmended->breachId,
                'company_id' => $change['companyId'],
                'field' => $change['field'],
                'old_value' => $change['old'],
                'new_value' => $change['new'],
                'reason' => $breachAmended->reason,
                'amended_at' => $breachAmended->amendedAt,
                'amended_by' => $breachAmended->amendedByUserId,
            ]);
        }
    }

    public function onBreachCompanyAdded(BreachCompanyAdded $breachCompanyAdded): void
    {
        BreachAmendment::query()->create([
            'breach_incident_id' => $breachCompanyAdded->breachId,
            'company_id' => $breachCompanyAdded->companyId,
            'field' => BreachAmendmentField::LateCompanyAdded,
            'old_value' => null,
            'new_value' => ['dataTypes' => $breachCompanyAdded->dataTypes, 'estimatedCount' => $breachCompanyAdded->estimatedCount],
            'reason' => $breachCompanyAdded->reason,
            'amended_at' => $breachCompanyAdded->addedAt,
            'amended_by' => $breachCompanyAdded->addedByUserId,
        ]);
    }
}
