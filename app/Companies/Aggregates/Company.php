<?php

namespace App\Companies\Aggregates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use App\Companies\Enums\CompanyStatus;
use App\Companies\Events\CompanyCreated;
use App\Companies\Enums\ResettableSetting;
use App\Foundation\EventStore\AggregateRoot;
use App\Companies\Events\CompanySettingReset;
use App\Companies\Events\CompanyTrialExpired;
use App\Companies\Events\CompanyDetailsUpdated;
use App\Companies\Events\CompanySettingsCreated;
use App\Companies\Events\CompanySettingsUpdated;
use App\Companies\Events\CompanyOwnershipTransferred;
use App\Companies\Events\CompanyDataProtectionOfficerSet;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Companies\UseCases\Commands\UpdateCompanyCommand;
use App\Companies\Exceptions\CompanyAlreadyExistsException;
use App\Companies\Events\CompanyDataProtectionOfficerRemoved;
use App\Companies\Events\CompanyOwnershipVerificationRecorded;
use App\Companies\UseCases\Commands\SetDataProtectionOfficerCommand;
use App\Companies\UseCases\Commands\TransferCompanyOwnershipCommand;
use App\Companies\UseCases\Commands\VerifyOwnershipTransferCaseCommand;

class Company extends AggregateRoot
{
    public const int TRIAL_DAYS = 30;

    protected ?CompanyStatus $status = null;

    public function create(CreateCompanyCommand $command): static
    {
        if ($this->status !== null) {
            throw CompanyAlreadyExistsException::withId($this->uuid());
        }

        return $this->forCompany($this->uuid())
            ->recordThat(new CompanyCreated(
                name: $command->name,
                brNumber: $command->brNumber,
                epfNumber: $command->epfNumber,
                country: $command->country,
                ownerUserId: $command->owner->getKey(),
                trialEndsAt: CarbonImmutable::now()->addDays(self::TRIAL_DAYS),
            ))
            ->recordThat(new CompanySettingsCreated(
                companyId: $this->uuid(),
                settingsId: Str::uuid7()->toString(),
            ));
    }

    /**
     * Update the editable details (UC-1-033). The details event fires only when
     * a name is supplied. A setting sent with its country's default over an own
     * value follows the country again (UC-1-058); the other sent settings become
     * own values.
     */
    public function updateDetails(UpdateCompanyCommand $command, SettingsBaseline $baseline, string $updatedBy): static
    {
        if ($command->name !== null) {
            $this->recordThat(new CompanyDetailsUpdated(
                name: $command->name,
                updatedBy: $updatedBy,
                updatedAt: CarbonImmutable::now(),
            ));
        }

        foreach ($baseline->settingsFollowingAgain($command->settingsSent()) as $setting) {
            $this->recordReset($setting, $updatedBy);
        }

        /** @var array{timezone?: string, fiscal_year_start_month?: int, working_days_per_week?: int, default_work_hours_per_day?: float} $ownValues */
        $ownValues = $baseline->ownValueChanges($command->settingsSent());

        if ($ownValues !== []) {
            $this->recordThat(new CompanySettingsUpdated(
                timezone: $ownValues['timezone'] ?? null,
                fiscalYearStartMonth: $ownValues['fiscal_year_start_month'] ?? null,
                workingDaysPerWeek: $ownValues['working_days_per_week'] ?? null,
                defaultWorkHoursPerDay: $ownValues['default_work_hours_per_day'] ?? null,
                updatedBy: $updatedBy,
                updatedAt: CarbonImmutable::now(),
            ));
        }

        return $this;
    }

    /**
     * Put one setting back on its country's default (UC-1-058); a setting that
     * already follows records nothing.
     */
    public function resetSetting(ResettableSetting $setting, SettingsBaseline $baseline, string $resetBy): static
    {
        if (! $baseline->hasOwnValue($setting)) {
            return $this;
        }

        return $this->recordReset($setting, $resetBy);
    }

    protected function recordReset(ResettableSetting $setting, string $resetBy): static
    {
        return $this->recordThat(new CompanySettingReset(
            setting: $setting->value,
            resetBy: $resetBy,
            resetAt: CarbonImmutable::now(),
        ));
    }

    /**
     * Record or replace the company's data protection officer (UC-1-146) — the values
     * encrypted before they reach the stream. Guardless: the interactor decides the no-op.
     */
    public function setDataProtectionOfficer(SetDataProtectionOfficerCommand $command, string $setBy): static
    {
        return $this->recordThat(new CompanyDataProtectionOfficerSet(
            encryptedName: Crypt::encryptString($command->name),
            encryptedEmail: Crypt::encryptString($command->email),
            encryptedPhone: $command->phone === null ? null : Crypt::encryptString($command->phone),
            setBy: $setBy,
            setAt: CarbonImmutable::now(),
        ));
    }

    public function removeDataProtectionOfficer(string $removedBy): static
    {
        return $this->recordThat(new CompanyDataProtectionOfficerRemoved(
            removedBy: $removedBy,
            removedAt: CarbonImmutable::now(),
        ));
    }

    public function trialExpire(): static
    {
        if ($this->status !== CompanyStatus::Trial) {
            return $this;
        }

        return $this->recordThat(new CompanyTrialExpired);
    }

    /**
     * Record the ownership move (UC-1-028 / UC-1-093). Guardless — eligibility
     * and staleness are decided at the interactor seam under row locks (T2
     * precedent), which supplies previousOwnerUserId from the locked read.
     */
    public function transferOwnership(TransferCompanyOwnershipCommand $command, string $previousOwnerUserId): static
    {
        return $this->recordThat(new CompanyOwnershipTransferred(
            previousOwnerUserId: $previousOwnerUserId,
            newOwnerUserId: $command->newOwnerUserId,
            transferredBy: $command->transferredBy->getKey(),
            source: $command->source->value,
            caseId: $command->case?->getKey(),
        ));
    }

    /**
     * Record how the identity behind a support-assisted handover was proven
     * (UC-1-113). Guardless — the open-only case guard lives at the interactor
     * seam (T2 precedent). Artefact METADATA only, never the prose notes
     * (spec §2 invariant: the Command projects entries to the closed shape).
     */
    public function recordOwnershipVerification(VerifyOwnershipTransferCaseCommand $command, string $verifiedBy): static
    {
        return $this->recordThat(new CompanyOwnershipVerificationRecorded(
            caseId: $command->caseId,
            verifiedBy: $verifiedBy,
            artefacts: $command->artefactMetadata(),
            verifiedAt: CarbonImmutable::now(),
        ));
    }

    protected function applyCompanyCreated(CompanyCreated $event): void
    {
        $this->status = CompanyStatus::Trial;
    }

    protected function applyCompanyTrialExpired(CompanyTrialExpired $event): void
    {
        $this->status = CompanyStatus::TrialExpired;
    }
}
