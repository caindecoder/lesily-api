<?php

namespace App\Companies\Aggregates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Companies\Enums\CompanyStatus;
use App\Companies\Events\CompanyCreated;
use App\Companies\Support\CountryDefaults;
use App\Foundation\EventStore\AggregateRoot;
use App\Companies\Events\CompanyTrialExpired;
use App\Companies\Events\CompanyDetailsUpdated;
use App\Companies\Events\CompanySettingsCreated;
use App\Companies\Events\CompanySettingsUpdated;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Companies\UseCases\Commands\UpdateCompanyCommand;
use App\Companies\Exceptions\CompanyAlreadyExistsException;

class Company extends AggregateRoot
{
    public const int TRIAL_DAYS = 30;

    protected ?CompanyStatus $status = null;

    public function create(CreateCompanyCommand $command): static
    {
        if ($this->status !== null) {
            throw CompanyAlreadyExistsException::withId($this->uuid());
        }

        $defaults = CountryDefaults::for($command->country);

        return $this->forCompany($this->uuid())
            ->recordThat(new CompanyCreated(
                name: $command->name,
                brNumber: $command->brNumber,
                epfNumber: $command->epfNumber,
                country: $command->country,
                ownerUserId: $command->ownerUserId,
                trialEndsAt: CarbonImmutable::now()->addDays(self::TRIAL_DAYS),
            ))
            ->recordThat(new CompanySettingsCreated(
                companyId: $this->uuid(),
                settingsId: (string) Str::uuid7(),
                timezone: $defaults['timezone'],
                currency: $defaults['currency'],
                localeDefault: $defaults['localeDefault'],
                fiscalYearStartMonth: $defaults['fiscalYearStartMonth'],
                workingDaysPerWeek: $defaults['workingDaysPerWeek'],
                defaultWorkHoursPerDay: $defaults['defaultWorkHoursPerDay'],
            ));
    }

    /**
     * Update the editable details (UC-1-033). Guardless (mirrors changePassword /
     * changeRole — name/settings are projected columns, not aggregate state), and
     * conditional: the details event fires only when a name is supplied, the
     * settings event only when a settings field is. forCompany re-tags the tenant
     * so the events carry company_id metadata even for a read-model-seeded company.
     */
    public function updateDetails(UpdateCompanyCommand $command, string $updatedBy): static
    {
        $this->forCompany($this->uuid());

        if ($command->name !== null) {
            $this->recordThat(new CompanyDetailsUpdated(
                name: $command->name,
                updatedBy: $updatedBy,
                updatedAt: CarbonImmutable::now(),
            ));
        }

        if ($this->hasSettingsChanges($command)) {
            $this->recordThat(new CompanySettingsUpdated(
                timezone: $command->timezone,
                fiscalYearStartMonth: $command->fiscalYearStartMonth,
                workingDaysPerWeek: $command->workingDaysPerWeek,
                defaultWorkHoursPerDay: $command->defaultWorkHoursPerDay,
                updatedBy: $updatedBy,
                updatedAt: CarbonImmutable::now(),
            ));
        }

        return $this;
    }

    private function hasSettingsChanges(UpdateCompanyCommand $command): bool
    {
        return $command->timezone !== null
            || $command->fiscalYearStartMonth !== null
            || $command->workingDaysPerWeek !== null
            || $command->defaultWorkHoursPerDay !== null;
    }

    public function trialExpire(): static
    {
        if ($this->status !== CompanyStatus::Trial) {
            return $this;
        }

        return $this->recordThat(new CompanyTrialExpired);
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
