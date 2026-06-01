<?php

namespace App\Companies\Aggregates;

use App\Companies\Events\CompanyCreated;
use App\Foundation\EventStore\AggregateRoot;
use App\Companies\Exceptions\CompanyAlreadyExistsException;

class Company extends AggregateRoot
{
    public const int TRIAL_DAYS = 30;

    protected bool $created = false;

    public function create(string $name): static
    {
        if ($this->created) {
            throw CompanyAlreadyExistsException::withId($this->uuid());
        }

        return $this->forCompany($this->uuid())
            ->recordThat(new CompanyCreated(name: $name, trialDays: self::TRIAL_DAYS));
    }

    protected function applyCompanyCreated(CompanyCreated $event): void
    {
        $this->created = true;
    }
}
