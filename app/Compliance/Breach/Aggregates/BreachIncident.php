<?php

namespace App\Compliance\Breach\Aggregates;

use App\Models\User;
use Carbon\CarbonImmutable;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\EventStore\AggregateRoot;
use App\Compliance\Breach\Enums\BreachDataType;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\UseCases\Commands\AffectedCompanyData;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;

class BreachIncident extends AggregateRoot
{
    private const int DEADLINE_HOURS = 72;

    protected ?BreachStage $stage = null;

    public function record(RecordBreachCommand $command, User $admin): static
    {
        return $this->recordThat(new BreachRecorded(
            breachId: $this->uuid(),
            recordedAt: CarbonImmutable::now(),
            recordedByUserId: $admin->getKey(),
            description: $command->description,
            likelyConsequences: $command->likelyConsequences,
            measures: $command->measures,
            dateDiscovered: $command->dateDiscovered,
            severity: $command->severity->value,
            deadline: $command->dateDiscovered->addHours(self::DEADLINE_HOURS),
            companies: array_map(fn (AffectedCompanyData $company): array => [
                'companyId' => $company->company->getKey(),
                'dataTypes' => array_map(fn (BreachDataType $type): string => $type->value, $company->dataTypes),
                'estimatedCount' => $company->estimatedCount,
            ], $command->companies),
        ));
    }

    protected function applyBreachRecorded(BreachRecorded $event): void
    {
        $this->stage = BreachStage::Recorded;
    }
}
