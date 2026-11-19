<?php

namespace App\Companies\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The company recorded or replaced its data protection officer (UC-1-146). The
 * values are ciphertext, so no stored copy holds them in plain text; companyId
 * rides the stream metadata.
 */
class CompanyDataProtectionOfficerSet extends ShouldBeStored
{
    public function __construct(
        public string $encryptedName,
        public string $encryptedEmail,
        public ?string $encryptedPhone,
        public string $setBy,
        public CarbonInterface $setAt,
    ) {}
}
