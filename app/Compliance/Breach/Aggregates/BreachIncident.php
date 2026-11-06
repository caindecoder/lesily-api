<?php

namespace App\Compliance\Breach\Aggregates;

use App\Models\User;
use Carbon\CarbonImmutable;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\EventStore\AggregateRoot;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Events\BreachCompaniesNotified;
use App\Compliance\Breach\UseCases\Commands\AffectedCompanyData;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;
use App\Compliance\Breach\Exceptions\BreachIncidentNotRecordedException;
use App\Compliance\Breach\Exceptions\BreachIncidentAlreadyRecordedException;

class BreachIncident extends AggregateRoot
{
    private const int DEADLINE_HOURS = 72;

    protected ?BreachStage $stage = null;

    public function record(RecordBreachCommand $command, User $admin): static
    {
        if ($this->stage !== null) {
            throw BreachIncidentAlreadyRecordedException::withId($this->uuid());
        }

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
            companies: array_map(fn (AffectedCompanyData $company): array => $company->facts(), $command->companies),
        ));
    }

    /**
     * @param  array<string, array<string, string>>  $parts  each affected company's own part, keyed by company id
     */
    public function notifyCompanies(User $admin, array $parts): static
    {
        if ($this->stage !== BreachStage::Recorded) {
            throw new BreachIncidentNotRecordedException($this->stage);
        }

        $notifiedAt = CarbonImmutable::now();

        foreach ($parts as $companyId => $part) {
            $this->recordThat(new BreachCompanyNotified(
                breachId: $this->uuid(),
                companyId: $companyId,
                notifiedAt: $notifiedAt,
                notifiedByUserId: $admin->getKey(),
                part: $part,
            ));
        }

        return $this->recordThat(new BreachCompaniesNotified(
            breachId: $this->uuid(),
            notifiedAt: $notifiedAt,
            notifiedByUserId: $admin->getKey(),
        ));
    }

    protected function applyBreachRecorded(BreachRecorded $event): void
    {
        $this->stage = BreachStage::Recorded;
    }

    protected function applyBreachCompaniesNotified(BreachCompaniesNotified $event): void
    {
        $this->stage = BreachStage::CompaniesNotified;
    }
}
