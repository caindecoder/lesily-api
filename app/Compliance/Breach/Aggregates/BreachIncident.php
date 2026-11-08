<?php

namespace App\Compliance\Breach\Aggregates;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\EventStore\AggregateRoot;
use App\Compliance\Breach\Values\BreachFacts;
use App\Compliance\Breach\Events\BreachAmended;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Values\RegulatorFiling;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Compliance\Breach\Events\BreachCompaniesNotified;
use App\Compliance\Breach\Events\BreachFiledWithRegulator;
use App\Compliance\Breach\Exceptions\NothingToAmendException;
use App\Compliance\Breach\UseCases\Commands\AmendBreachCommand;
use App\Compliance\Breach\UseCases\Commands\AffectedCompanyData;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;
use App\Compliance\Breach\Exceptions\BreachFilingNotOpenException;
use App\Compliance\Breach\Exceptions\BreachAmendmentRefusedException;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;
use App\Compliance\Breach\Exceptions\BreachIncidentNotRecordedException;
use App\Compliance\Breach\UseCases\Commands\FileBreachWithRegulatorCommand;
use App\Compliance\Breach\Exceptions\BreachIncidentAlreadyRecordedException;

class BreachIncident extends AggregateRoot
{
    private const int DEADLINE_HOURS = 72;

    protected ?BreachStage $stage = null;

    protected CarbonInterface $deadline;

    protected bool $deadlineMissed = false;

    protected BreachFacts $facts;

    /** @var array<string, CarbonInterface> each notified company's notice time, keyed by company id */
    protected array $notifiedAt = [];

    /** @var array<string, CarbonInterface> each company's filing-request time, keyed by company id */
    protected array $filingRequestedAt = [];

    /** @var array<string, CarbonInterface> each company's regulator filing time, keyed by company id */
    protected array $filedAt = [];

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

    public function requestFiling(RequestBreachFilingCommand $command, User $owner): static
    {
        $companyId = $command->company->getKey();

        if (! isset($this->notifiedAt[$companyId])) {
            throw BreachFilingNotOpenException::notNotified();
        }

        if (isset($this->filedAt[$companyId])) {
            throw BreachFilingNotOpenException::alreadyFiled();
        }

        if (isset($this->filingRequestedAt[$companyId])) {
            throw BreachFilingNotOpenException::alreadyRequested();
        }

        return $this->recordThat(new BreachFilingRequested(
            breachId: $this->uuid(),
            companyId: $companyId,
            requestedAt: CarbonImmutable::now(),
            requestedByUserId: $owner->getKey(),
        ));
    }

    public function fileWithRegulator(FileBreachWithRegulatorCommand $command, User $admin, RegulatorFiling $filing): static
    {
        $companyId = $command->company->getKey();

        if (isset($this->filedAt[$companyId])) {
            throw BreachFilingNotOpenException::alreadyFiled();
        }

        if (! isset($this->filingRequestedAt[$companyId])) {
            throw BreachFilingNotOpenException::notRequested();
        }

        return $this->recordThat(new BreachFiledWithRegulator(
            breachId: $this->uuid(),
            companyId: $companyId,
            companyName: $filing->companyName,
            filedAt: CarbonImmutable::now(),
            filedByUserId: $admin->getKey(),
            regulatorAddress: $filing->regulatorAddress,
            part: $filing->part,
            bodyHash: $filing->bodyHash,
        ));
    }

    public function amend(AmendBreachCommand $command, User $admin): static
    {
        $changes = $this->facts->changesTo($command);

        if ($changes === []) {
            throw NothingToAmendException::everyValueMatches();
        }

        if ($this->stage === BreachStage::CompaniesNotified) {
            foreach ($changes as $change) {
                $this->guardOpenAfterTheNotices(BreachAmendmentField::from($change['field']));
            }
        }

        $amendedAt = CarbonImmutable::now();
        $deadline = $this->facts->amendedBy($changes)->dateDiscovered->addHours(self::DEADLINE_HOURS);

        return $this->recordThat(new BreachAmended(
            breachId: $this->uuid(),
            amendedAt: $amendedAt,
            amendedByUserId: $admin->getKey(),
            reason: $command->reason,
            changes: $changes,
            deadline: $deadline,
            missedDeadlineCleared: $this->deadlineMissed && $deadline->greaterThan($amendedAt),
        ));
    }

    protected function applyBreachAmended(BreachAmended $event): void
    {
        $this->facts = $this->facts->amendedBy($event->changes);
        $this->deadline = $event->deadline;
        $this->deadlineMissed = $this->deadlineMissed && ! $event->missedDeadlineCleared;
    }

    protected function guardOpenAfterTheNotices(BreachAmendmentField $field): void
    {
        match ($field) {
            BreachAmendmentField::DateDiscovered => throw BreachAmendmentRefusedException::discoveryDateLocked(),
            BreachAmendmentField::Severity => throw BreachAmendmentRefusedException::severityLocked(),
            BreachAmendmentField::CompanyAdded, BreachAmendmentField::CompanyRemoved => throw BreachAmendmentRefusedException::companyListLocked(),
            default => null,
        };
    }

    public function flagMissedDeadline(CarbonInterface $at): static
    {
        if ($this->stage !== BreachStage::Recorded || $this->deadlineMissed || $at->lessThan($this->deadline)) {
            return $this;
        }

        return $this->recordThat(new BreachDeadlineMissed(breachId: $this->uuid(), missedAt: $at));
    }

    protected function applyBreachRecorded(BreachRecorded $event): void
    {
        $this->stage = BreachStage::Recorded;
        $this->deadline = $event->deadline;
        $this->facts = BreachFacts::recordedBy($event);
    }

    protected function applyBreachDeadlineMissed(BreachDeadlineMissed $event): void
    {
        $this->deadlineMissed = true;
    }

    protected function applyBreachCompanyNotified(BreachCompanyNotified $event): void
    {
        $this->notifiedAt[$event->companyId] = $event->notifiedAt;
    }

    protected function applyBreachCompaniesNotified(BreachCompaniesNotified $event): void
    {
        $this->stage = BreachStage::CompaniesNotified;
    }

    protected function applyBreachFilingRequested(BreachFilingRequested $event): void
    {
        $this->filingRequestedAt[$event->companyId] = $event->requestedAt;
    }

    protected function applyBreachFiledWithRegulator(BreachFiledWithRegulator $event): void
    {
        $this->filedAt[$event->companyId] = $event->filedAt;
    }
}
