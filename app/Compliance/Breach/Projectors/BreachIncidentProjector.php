<?php

namespace App\Compliance\Breach\Projectors;

use Carbon\CarbonImmutable;
use App\Compliance\Breach\Enums\BreachStage;
use App\Compliance\Breach\Events\BreachAmended;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Events\BreachResolved;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Events\BreachCompaniesNotified;
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

    public function onBreachResolved(BreachResolved $event): void
    {
        BreachIncidentRecord::query()->whereKey($event->breachId)->update([
            'stage' => BreachStage::Resolved,
            'resolved_at' => $event->resolvedAt,
            'resolved_by' => $event->resolvedByUserId,
            'root_cause' => $event->rootCause,
            'remediation_steps' => $event->remediationSteps,
            'resolution_summary' => $event->summary,
        ]);
    }

    public function onBreachAmended(BreachAmended $event): void
    {
        foreach ($event->changes as $change) {
            $this->applyChange($event, $change);
        }

        BreachIncidentRecord::query()->whereKey($event->breachId)->update(array_merge(
            ['deadline' => $event->deadline],
            $event->missedDeadlineCleared ? ['deadline_missed_at' => null] : [],
        ));
    }

    /**
     * @param  array{field: string, companyId: ?string, old: mixed, new: mixed}  $change
     */
    protected function applyChange(BreachAmended $event, array $change): void
    {
        $incident = BreachIncidentRecord::query()->whereKey($event->breachId);

        match (BreachAmendmentField::from($change['field'])) {
            BreachAmendmentField::Description => $incident->update(['description' => $change['new']]),
            BreachAmendmentField::LikelyConsequences => $incident->update(['likely_consequences' => $change['new']]),
            BreachAmendmentField::Measures => $incident->update(['measures' => $change['new']]),
            BreachAmendmentField::DateDiscovered => $incident->update(['date_discovered' => CarbonImmutable::parse($change['new'])]),
            BreachAmendmentField::Severity => $incident->update(['severity' => $change['new']]),
            default => null,
        };
    }
}
