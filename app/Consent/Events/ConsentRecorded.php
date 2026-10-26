<?php

namespace App\Consent\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Version-stamped, append-only consent record (UC-1-069, PDPA). The stored event
 * is the source; ConsentRecordProjector copies it into consent_records. The
 * event's own created_at carries the consent timestamp.
 */
class ConsentRecorded extends ShouldBeStored
{
    public function __construct(
        public string $userId,
        public string $companyId,
        public string $policyType,
        public string $version,
        public string $ip,
    ) {}
}
