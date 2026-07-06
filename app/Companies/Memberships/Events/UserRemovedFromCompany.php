<?php

namespace App\Companies\Memberships\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-01 T8: a member's company access was revoked (UC-1-024). Payload trimmed
 * (R-C parity, s113): companyId rides the stream metadata (genesis replay
 * re-tags it) and userId is derivable via the membershipId join; removedAt stays
 * explicit for the audit postcondition. The employee-record unlink is deferred
 * to chunk-2 (no employee model yet).
 */
class UserRemovedFromCompany extends ShouldBeStored
{
    public function __construct(
        public string $membershipId,
        public string $removedBy,
        public CarbonInterface $removedAt,
    ) {}
}
