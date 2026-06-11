<?php

namespace App\Companies\Aggregates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Companies\Enums\CompanyStatus;
use App\Companies\Events\CompanyCreated;
use App\Companies\Support\CountryDefaults;
use App\Foundation\EventStore\AggregateRoot;
use App\Companies\Events\CompanyTrialExpired;
use App\Companies\Events\CompanySettingsCreated;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
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
