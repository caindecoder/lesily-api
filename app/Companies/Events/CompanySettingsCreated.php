<?php

namespace App\Companies\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A new company's settings row — emitted alongside CompanyCreated in the same
 * record (CD-1-02) so CompanySettingsProjector can listen narrowly without
 * parsing the genesis. An empty setting follows the company's country.
 */
class CompanySettingsCreated extends ShouldBeStored
{
    public function __construct(
        public string $companyId,
        public string $settingsId,
        public ?string $timezone = null,
        public ?string $currency = null,
        public ?string $localeDefault = null,
        public ?int $fiscalYearStartMonth = null,
        public ?int $workingDaysPerWeek = null,
        public ?float $defaultWorkHoursPerDay = null,
    ) {}
}
