<?php

namespace App\Companies\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A platform admin confirmed the identity behind a support-assisted ownership
 * handover (UC-1-113; slice-11R spec §2). Carries artefact METADATA only —
 * the prose notes live solely on the mutable case row and have no property
 * here by design, so they can never reach the immutable stream.
 */
class CompanyOwnershipVerificationRecorded extends ShouldBeStored
{
    /**
     * @param  array<int, array{type: string, reference: string, capturedAt: string}>  $artefacts
     */
    public function __construct(
        public string $caseId,
        public string $verifiedBy,
        public array $artefacts,
        public CarbonInterface $verifiedAt,
    ) {}
}
