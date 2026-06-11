<?php

namespace App\Companies\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Initial settings seeded from the country defaults at company creation —
 * emitted alongside CompanyCreated in the same record (CD-1-02) so
 * CompanySettingsProjector can listen narrowly without parsing the genesis.
 */
class CompanySettingsCreated extends ShouldBeStored
{
    public function __construct(
        public string $companyId,
        public string $settingsId,
        public string $timezone,
        public string $currency,
        public string $localeDefault,
        public int $fiscalYearStartMonth,
        public int $workingDaysPerWeek,
        public float $defaultWorkHoursPerDay,
    ) {}
}
