<?php

namespace App\Companies\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The company's editable settings changed (UC-1-033; a non-lifecycle attribute
 * mutation, no SM transition). Only the submitted fields are non-null and the
 * projector writes exactly those (partial update). companyId rides the stream
 * metadata (the projector reads aggregateRootUuid); updatedBy is the acting
 * owner/admin and updatedAt anchors the audit trail.
 */
class CompanySettingsUpdated extends ShouldBeStored
{
    public function __construct(
        public ?string $timezone,
        public ?int $fiscalYearStartMonth,
        public ?int $workingDaysPerWeek,
        public ?float $defaultWorkHoursPerDay,
        public string $updatedBy,
        public CarbonInterface $updatedAt,
    ) {}
}
