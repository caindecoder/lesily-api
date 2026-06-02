<?php

namespace App\Companies\Aggregates;

use Carbon\CarbonImmutable;
use App\Companies\Enums\CompanyStatus;
use App\Companies\Events\CompanyCreated;
use App\Foundation\EventStore\AggregateRoot;
use App\Companies\Events\CompanyTrialExpired;
use App\Companies\Exceptions\CompanyAlreadyExistsException;

class Company extends AggregateRoot
{
    public const int TRIAL_DAYS = 30;

    protected ?CompanyStatus $status = null;

    public function create(string $name): static
    {
        if ($this->status !== null) {
            throw CompanyAlreadyExistsException::withId($this->uuid());
        }

        return $this->forCompany($this->uuid())
            ->recordThat(new CompanyCreated(
                name: $name,
                trialEndsAt: CarbonImmutable::now()->addDays(self::TRIAL_DAYS),
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
