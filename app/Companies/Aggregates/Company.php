<?php

namespace App\Companies\Aggregates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
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
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Companies\UseCases\Commands\UpdateCompanyCommand;
use App\Companies\Exceptions\CompanyAlreadyExistsException;
use App\Companies\Events\CompanyOwnershipVerificationRecorded;
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
     * own values. forCompany re-tags the tenant so the events carry company_id
     * metadata even for a read-model-seeded company.
     */
    public function updateDetails(UpdateCompanyCommand $command, SettingsBaseline $baseline, string $updatedBy): static
    {
        $this->forCompany($this->uuid());

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

        $ownValues = $command->withoutSettings($baseline->settingsAtTheCountryDefault($command->settingsSent()));

        if ($ownValues->settingsSent() !== []) {
            $this->recordThat(new CompanySettingsUpdated(
                timezone: $ownValues->timezone,
                fiscalYearStartMonth: $ownValues->fiscalYearStartMonth,
                workingDaysPerWeek: $ownValues->workingDaysPerWeek,
                defaultWorkHoursPerDay: $ownValues->defaultWorkHoursPerDay,
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

        $this->forCompany($this->uuid());

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
     * forCompany re-tags the tenant (updateDetails parity).
     */
    public function transferOwnership(TransferCompanyOwnershipCommand $command, string $previousOwnerUserId): static
    {
        $this->forCompany($this->uuid());

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
     * forCompany re-tags the tenant (updateDetails parity).
     */
    public function recordOwnershipVerification(VerifyOwnershipTransferCaseCommand $command, string $verifiedBy): static
    {
        $this->forCompany($this->uuid());

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
