<?php

namespace App\Companies\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The company's editable identity changed — its name (UC-1-033; a non-lifecycle
 * attribute mutation, no SM transition). companyId rides the stream metadata (the
 * base AggregateRoot tags it; the projector reads aggregateRootUuid), so it is not
 * duplicated in the payload. updatedBy is the acting owner/admin; updatedAt anchors
 * the audit trail the future audit-trail slice consumes.
 */
class CompanyDetailsUpdated extends ShouldBeStored
{
    public function __construct(
        public string $name,
        public string $updatedBy,
        public CarbonInterface $updatedAt,
    ) {}
}
