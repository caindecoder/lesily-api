<?php

namespace App\Companies\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The company's editable settings changed (UC-1-033; a non-lifecycle attribute
 * mutation, no SM transition). Only the fields that become own values are
 * non-null and the projector writes exactly those (partial update). A field
 * sent with its country's value is recorded as CompanySettingReset or not at
 * all (UC-1-058). companyId rides the stream
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
