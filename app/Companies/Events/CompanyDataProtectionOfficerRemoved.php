<?php

namespace App\Companies\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The company removed its data protection officer (UC-1-146); companyId rides the
 * stream metadata.
 */
class CompanyDataProtectionOfficerRemoved extends ShouldBeStored
{
    public function __construct(
        public string $removedBy,
        public CarbonInterface $removedAt,
    ) {}
}
