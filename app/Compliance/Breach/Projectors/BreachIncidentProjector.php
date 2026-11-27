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
use App\Compliance\Breach\Events\BreachMarkedFalseAlarm;
use App\Compliance\Breach\Events\BreachCompaniesNotified;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class BreachIncidentProjector extends Projector
{
    public function onBreachRecorded(BreachRecorded $breachRecorded): void
    {
        BreachIncidentRecord::query()->create([
            'id' => $breachRecorded->breachId,
            'stage' => BreachStage::Recorded,
            'severity' => $breachRecorded->severity,
            'description' => $breachRecorded->description,
            'likely_consequences' => $breachRecorded->likelyConsequences,
            'measures' => $breachRecorded->measures,
            'date_discovered' => $breachRecorded->dateDiscovered,
            'deadline' => $breachRecorded->deadline,
            'recorded_at' => $breachRecorded->recordedAt,
            'recorded_by' => $breachRecorded->recordedByUserId,
        ]);
    }

    public function onBreachDeadlineMissed(BreachDeadlineMissed $breachDeadlineMissed): void
    {
        BreachIncidentRecord::query()->whereKey($breachDeadlineMissed->breachId)->update(['deadline_missed_at' => $breachDeadlineMissed->missedAt]);
    }

    public function onBreachCompaniesNotified(BreachCompaniesNotified $breachCompaniesNotified): void
    {
        BreachIncidentRecord::query()->whereKey($breachCompaniesNotified->breachId)->update([
            'stage' => BreachStage::CompaniesNotified,
            'companies_notified_at' => $breachCompaniesNotified->notifiedAt,
        ]);
    }

    public function onBreachResolved(BreachResolved $breachResolved): void
    {
        BreachIncidentRecord::query()->whereKey($breachResolved->breachId)->update([
            'stage' => BreachStage::Resolved,
            'resolved_at' => $breachResolved->resolvedAt,
            'resolved_by' => $breachResolved->resolvedByUserId,
            'root_cause' => $breachResolved->rootCause,
            'remediation_steps' => $breachResolved->remediationSteps,
            'resolution_summary' => $breachResolved->summary,
        ]);
    }

    public function onBreachMarkedFalseAlarm(BreachMarkedFalseAlarm $breachMarkedFalseAlarm): void
    {
        BreachIncidentRecord::query()->whereKey($breachMarkedFalseAlarm->breachId)->update([
            'stage' => BreachStage::FalseAlarm,
            'false_alarm_at' => $breachMarkedFalseAlarm->markedAt,
            'false_alarm_by' => $breachMarkedFalseAlarm->markedByUserId,
            'false_alarm_reason' => $breachMarkedFalseAlarm->reason,
            'false_alarm_findings' => $breachMarkedFalseAlarm->findings,
        ]);
    }

    public function onBreachAmended(BreachAmended $breachAmended): void
    {
        foreach ($breachAmended->changes as $change) {
            $this->applyChange($breachAmended, $change);
        }

        BreachIncidentRecord::query()->whereKey($breachAmended->breachId)->update(array_merge(
            ['deadline' => $breachAmended->deadline],
            $breachAmended->missedDeadlineCleared ? ['deadline_missed_at' => null] : [],
        ));
    }

    /**
     * @param  array{field: string, companyId: ?string, old: mixed, new: mixed}  $change
     */
    protected function applyChange(BreachAmended $breachAmended, array $change): void
    {
        $breachIncidents = BreachIncidentRecord::query()->whereKey($breachAmended->breachId);

        match (BreachAmendmentField::from($change['field'])) {
            BreachAmendmentField::Description => $breachIncidents->update(['description' => $change['new']]),
            BreachAmendmentField::LikelyConsequences => $breachIncidents->update(['likely_consequences' => $change['new']]),
            BreachAmendmentField::Measures => $breachIncidents->update(['measures' => $change['new']]),
            BreachAmendmentField::DateDiscovered => $breachIncidents->update(['date_discovered' => CarbonImmutable::parse($change['new'])]),
            BreachAmendmentField::Severity => $breachIncidents->update(['severity' => $change['new']]),
            default => null,
        };
    }
}
