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
use App\Compliance\Breach\Events\BreachResolved;
use App\Compliance\Breach\Values\AffectedCompany;
use App\Compliance\Breach\Values\RegulatorFiling;
use App\Compliance\Breach\Events\BreachCompanyAdded;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use App\Compliance\Breach\Events\BreachDeadlineMissed;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use App\Compliance\Breach\Events\BreachFilingRequested;
use App\Compliance\Breach\Events\BreachMarkedFalseAlarm;
use App\Compliance\Breach\Events\BreachCompaniesNotified;
use App\Compliance\Breach\Events\BreachFiledWithRegulator;
use App\Compliance\Breach\Exceptions\NothingToAmendException;
use App\Compliance\Breach\UseCases\Commands\AmendBreachCommand;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;
use App\Compliance\Breach\UseCases\Commands\ResolveBreachCommand;
use App\Compliance\Breach\Exceptions\BreachFilingNotOpenException;
use App\Compliance\Breach\UseCases\Commands\AddBreachCompanyCommand;
use App\Compliance\Breach\Exceptions\BreachAmendmentRefusedException;
use App\Compliance\Breach\Exceptions\BreachFalseAlarmRefusedException;
use App\Compliance\Breach\Exceptions\BreachResolutionRefusedException;
use App\Compliance\Breach\Exceptions\LateBreachCompanyRefusedException;
use App\Compliance\Breach\UseCases\Commands\RequestBreachFilingCommand;
use App\Compliance\Breach\Exceptions\BreachIncidentNotRecordedException;
use App\Compliance\Breach\UseCases\Commands\MarkBreachFalseAlarmCommand;
use App\Compliance\Breach\UseCases\Commands\FileBreachWithRegulatorCommand;
use App\Compliance\Breach\Exceptions\BreachIncidentAlreadyRecordedException;

class BreachIncident extends AggregateRoot
{
    private const int DEADLINE_HOURS = 72;

    protected ?BreachStage $breachStage = null;

    protected CarbonInterface $notificationDeadline;

    protected bool $deadlineMissed = false;

    protected BreachFacts $breachFacts;

    /** @var array<string, CarbonInterface> each notified company's notice time, keyed by company id */
    protected array $notifiedAt = [];

    /** @var array<string, CarbonInterface> each company's filing-request time, keyed by company id */
    protected array $filingRequestedAt = [];

    /** @var array<string, CarbonInterface> each company's regulator filing time, keyed by company id */
    protected array $filedAt = [];

    public function record(RecordBreachCommand $recordBreachCommand, User $platformAdmin): static
    {
        if ($this->breachStage !== null) {
            throw BreachIncidentAlreadyRecordedException::withId($this->uuid());
        }

        return $this->recordThat(new BreachRecorded(
            breachId: $this->uuid(),
            recordedAt: CarbonImmutable::now(),
            recordedByUserId: $platformAdmin->getKey(),
            description: $recordBreachCommand->description,
            likelyConsequences: $recordBreachCommand->likelyConsequences,
            measures: $recordBreachCommand->measures,
            dateDiscovered: $recordBreachCommand->dateDiscovered,
            severity: $recordBreachCommand->severity->value,
            deadline: $recordBreachCommand->dateDiscovered->addHours(self::DEADLINE_HOURS),
            companies: array_map(fn (AffectedCompany $affectedCompany): array => $affectedCompany->facts(), $recordBreachCommand->companies),
        ));
    }

    /**
     * @param  array<string, array<string, string>>  $parts  each affected company's own part, keyed by company id
     */
    public function notifyCompanies(User $platformAdmin, array $parts): static
    {
        if ($this->breachStage !== BreachStage::Recorded) {
            throw new BreachIncidentNotRecordedException($this->breachStage);
        }

        $notifiedAt = CarbonImmutable::now();

        foreach ($parts as $companyId => $part) {
            $this->recordThat(new BreachCompanyNotified(
                breachId: $this->uuid(),
                companyId: $companyId,
                notifiedAt: $notifiedAt,
                notifiedByUserId: $platformAdmin->getKey(),
                part: $part,
            ));
        }

        return $this->recordThat(new BreachCompaniesNotified(
            breachId: $this->uuid(),
            notifiedAt: $notifiedAt,
            notifiedByUserId: $platformAdmin->getKey(),
        ));
    }

    /**
     * @param  array<string, string>  $part  the late company's own part of the incident, as sent
     */
    public function addLateCompany(AddBreachCompanyCommand $addBreachCompanyCommand, User $platformAdmin, array $part): static
    {
        if ($this->breachStage === BreachStage::Resolved) {
            throw LateBreachCompanyRefusedException::incidentResolved();
        }

        if ($this->breachStage === BreachStage::FalseAlarm) {
            throw LateBreachCompanyRefusedException::falseAlarm();
        }

        if ($this->breachStage !== BreachStage::CompaniesNotified) {
            throw LateBreachCompanyRefusedException::notYetNotified();
        }

        if (array_key_exists($addBreachCompanyCommand->company->getKey(), $this->breachFacts->companies)) {
            throw LateBreachCompanyRefusedException::alreadyOnTheIncident();
        }

        $addedAt = CarbonImmutable::now();
        $figures = $addBreachCompanyCommand->figures();

        $this->recordThat(new BreachCompanyAdded(
            breachId: $this->uuid(),
            companyId: $addBreachCompanyCommand->company->getKey(),
            dataTypes: $figures['dataTypes'],
            estimatedCount: $figures['estimatedCount'],
            reason: $addBreachCompanyCommand->reason,
            addedAt: $addedAt,
            addedByUserId: $platformAdmin->getKey(),
        ));

        return $this->recordThat(new BreachCompanyNotified(
            breachId: $this->uuid(),
            companyId: $addBreachCompanyCommand->company->getKey(),
            notifiedAt: $addedAt,
            notifiedByUserId: $platformAdmin->getKey(),
            part: $part,
        ));
    }

    public function requestFiling(RequestBreachFilingCommand $requestBreachFilingCommand, User $owner): static
    {
        if ($this->breachStage === BreachStage::Resolved) {
            throw BreachFilingNotOpenException::resolved();
        }

        $companyId = $requestBreachFilingCommand->company->getKey();

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

    public function fileWithRegulator(FileBreachWithRegulatorCommand $fileBreachWithRegulatorCommand, User $platformAdmin, RegulatorFiling $regulatorFiling): static
    {
        if ($this->breachStage === BreachStage::Resolved) {
            throw BreachFilingNotOpenException::incidentResolved();
        }

        if ($this->breachStage === BreachStage::FalseAlarm) {
            throw BreachFilingNotOpenException::falseAlarm();
        }

        $companyId = $fileBreachWithRegulatorCommand->company->getKey();

        if (isset($this->filedAt[$companyId])) {
            throw BreachFilingNotOpenException::alreadyFiled();
        }

        if (! isset($this->filingRequestedAt[$companyId])) {
            throw BreachFilingNotOpenException::notRequested();
        }

        return $this->recordThat(new BreachFiledWithRegulator(
            breachId: $this->uuid(),
            companyId: $companyId,
            companyName: $regulatorFiling->companyName,
            filedAt: CarbonImmutable::now(),
            filedByUserId: $platformAdmin->getKey(),
            regulatorAddress: $regulatorFiling->regulatorAddress,
            part: $regulatorFiling->part,
            bodyHash: $regulatorFiling->bodyHash,
        ));
    }

    public function amend(AmendBreachCommand $amendBreachCommand, User $platformAdmin): static
    {
        if ($this->breachStage === BreachStage::Resolved) {
            throw BreachAmendmentRefusedException::incidentResolved();
        }

        if ($this->breachStage === BreachStage::FalseAlarm) {
            throw BreachAmendmentRefusedException::falseAlarm();
        }

        $changes = $this->breachFacts->changesTo($amendBreachCommand);

        if ($changes === []) {
            throw NothingToAmendException::everyValueMatches();
        }

        if ($this->breachStage === BreachStage::CompaniesNotified) {
            foreach ($changes as $change) {
                $this->guardOpenAfterTheNotices(BreachAmendmentField::from($change['field']));
            }
        }

        $amendedAt = CarbonImmutable::now();
        $notificationDeadline = $this->breachFacts->amendedBy($changes)->discoveredAt->addHours(self::DEADLINE_HOURS);

        return $this->recordThat(new BreachAmended(
            breachId: $this->uuid(),
            amendedAt: $amendedAt,
            amendedByUserId: $platformAdmin->getKey(),
            reason: $amendBreachCommand->reason,
            changes: $changes,
            deadline: $notificationDeadline,
            missedDeadlineCleared: $this->deadlineMissed && $notificationDeadline->greaterThan($amendedAt),
        ));
    }

    public function resolve(ResolveBreachCommand $resolveBreachCommand, User $platformAdmin): static
    {
        if ($this->breachStage === BreachStage::Resolved) {
            throw BreachResolutionRefusedException::incidentResolved();
        }

        if ($this->breachStage === BreachStage::FalseAlarm) {
            throw BreachResolutionRefusedException::falseAlarm();
        }

        if ($this->breachStage !== BreachStage::CompaniesNotified) {
            throw BreachResolutionRefusedException::notNotified();
        }

        if (CarbonImmutable::now()->lessThan($this->notificationDeadline)) {
            throw BreachResolutionRefusedException::beforeTheDeadline();
        }

        if (array_diff_key($this->filingRequestedAt, $this->filedAt) !== []) {
            throw BreachResolutionRefusedException::filingPending();
        }

        return $this->recordThat(new BreachResolved(
            breachId: $this->uuid(),
            resolvedAt: CarbonImmutable::now(),
            resolvedByUserId: $platformAdmin->getKey(),
            rootCause: $resolveBreachCommand->rootCause,
            remediationSteps: $resolveBreachCommand->remediationSteps,
            summary: $resolveBreachCommand->summary,
        ));
    }

    protected function applyBreachResolved(BreachResolved $breachResolved): void
    {
        $this->breachStage = BreachStage::Resolved;
    }

    protected function applyBreachAmended(BreachAmended $breachAmended): void
    {
        $this->breachFacts = $this->breachFacts->amendedBy($breachAmended->changes);
        $this->notificationDeadline = $breachAmended->deadline;
        $this->deadlineMissed = $this->deadlineMissed && ! $breachAmended->missedDeadlineCleared;
    }

    protected function guardOpenAfterTheNotices(BreachAmendmentField $breachAmendmentField): void
    {
        match ($breachAmendmentField) {
            BreachAmendmentField::DateDiscovered => throw BreachAmendmentRefusedException::discoveryDateLocked(),
            BreachAmendmentField::Severity => throw BreachAmendmentRefusedException::severityLocked(),
            BreachAmendmentField::CompanyAdded, BreachAmendmentField::CompanyRemoved => throw BreachAmendmentRefusedException::companyListLocked(),
            default => null,
        };
    }

    public function markFalseAlarm(MarkBreachFalseAlarmCommand $markBreachFalseAlarmCommand, User $platformAdmin): static
    {
        match ($this->breachStage) {
            BreachStage::CompaniesNotified => throw BreachFalseAlarmRefusedException::companiesNotified(),
            BreachStage::Resolved => throw BreachFalseAlarmRefusedException::incidentResolved(),
            BreachStage::FalseAlarm => throw BreachFalseAlarmRefusedException::alreadyMarked(),
            null => throw BreachFalseAlarmRefusedException::notRecorded(),
            BreachStage::Recorded => null,
        };

        return $this->recordThat(new BreachMarkedFalseAlarm(
            breachId: $this->uuid(),
            markedAt: CarbonImmutable::now(),
            markedByUserId: $platformAdmin->getKey(),
            reason: $markBreachFalseAlarmCommand->reason,
            findings: $markBreachFalseAlarmCommand->findings,
        ));
    }

    protected function applyBreachMarkedFalseAlarm(BreachMarkedFalseAlarm $breachMarkedFalseAlarm): void
    {
        $this->breachStage = BreachStage::FalseAlarm;
    }

    public function flagMissedDeadline(CarbonInterface $missedAt): static
    {
        if ($this->breachStage !== BreachStage::Recorded || $this->deadlineMissed || $missedAt->lessThan($this->notificationDeadline)) {
            return $this;
        }

        return $this->recordThat(new BreachDeadlineMissed(breachId: $this->uuid(), missedAt: $missedAt));
    }

    protected function applyBreachRecorded(BreachRecorded $breachRecorded): void
    {
        $this->breachStage = BreachStage::Recorded;
        $this->notificationDeadline = $breachRecorded->deadline;
        $this->breachFacts = BreachFacts::recordedBy($breachRecorded);
    }

    protected function applyBreachDeadlineMissed(BreachDeadlineMissed $breachDeadlineMissed): void
    {
        $this->deadlineMissed = true;
    }

    protected function applyBreachCompanyAdded(BreachCompanyAdded $breachCompanyAdded): void
    {
        $this->breachFacts = $this->breachFacts->amendedBy([[
            'field' => BreachAmendmentField::LateCompanyAdded->value,
            'companyId' => $breachCompanyAdded->companyId,
            'old' => null,
            'new' => ['dataTypes' => $breachCompanyAdded->dataTypes, 'estimatedCount' => $breachCompanyAdded->estimatedCount],
        ]]);
    }

    protected function applyBreachCompanyNotified(BreachCompanyNotified $breachCompanyNotified): void
    {
        $this->notifiedAt[$breachCompanyNotified->companyId] = $breachCompanyNotified->notifiedAt;
    }

    protected function applyBreachCompaniesNotified(BreachCompaniesNotified $breachCompaniesNotified): void
    {
        $this->breachStage = BreachStage::CompaniesNotified;
    }

    protected function applyBreachFilingRequested(BreachFilingRequested $breachFilingRequested): void
    {
        $this->filingRequestedAt[$breachFilingRequested->companyId] = $breachFilingRequested->requestedAt;
    }

    protected function applyBreachFiledWithRegulator(BreachFiledWithRegulator $breachFiledWithRegulator): void
    {
        $this->filedAt[$breachFiledWithRegulator->companyId] = $breachFiledWithRegulator->filedAt;
    }
}
