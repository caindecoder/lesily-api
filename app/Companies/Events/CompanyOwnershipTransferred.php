<?php

namespace App\Companies\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Company ownership moved to another member (UC-1-028 routine / UC-1-093 admin
 * execute — spec §2). The company is the aggregate (companyId = aggregateRootUuid);
 * `source` names the path that moved it and `caseId` ties an admin execution to
 * its ownership_transfer_cases row. Role ids are NOT carried — the projector
 * resolves the seeded owner/admin roles by code at apply time.
 */
class CompanyOwnershipTransferred extends ShouldBeStored
{
    public function __construct(
        public string $previousOwnerUserId,
        public string $newOwnerUserId,
        public string $transferredBy,
        public string $source,
        public ?string $caseId = null,
    ) {}
}
