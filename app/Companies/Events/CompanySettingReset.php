<?php

namespace App\Companies\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A company setting went back to following its country (UC-1-058). The
 * setting is its column name; companyId rides the stream metadata.
 */
class CompanySettingReset extends ShouldBeStored
{
    public function __construct(
        public string $setting,
        public string $resetBy,
        public CarbonInterface $resetAt,
    ) {}
}
