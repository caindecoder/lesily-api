<?php

namespace App\Companies\OwnershipTransfers\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-05 T2: Requested → Verified (UC-1-113). The legally-significant midpoint
 * of a transfer — this payload IS the chain-of-custody evidence, so verifiedBy,
 * the verbatim notes and the artefact references live on the EVENT, not only on
 * the audit row: the defensibility chain must survive a read-model rebuild.
 *
 * Artefact METADATA only (references + capture times), never document bytes —
 * the event store stays free of PII blobs (OQ-1-045 / D4).
 *
 * CarbonInterface — spatie denormalizes replayed timestamps to mutable Carbon
 * (trial-lifecycle #37 lesson).
 *
 * @phpstan-type ArtefactMetadata array{type: string, reference: string, capturedAt: string}
 */
class OwnershipTransferIdentityVerified extends ShouldBeStored
{
    /**
     * @param  list<ArtefactMetadata>  $verificationArtefacts
     */
    public function __construct(
        public string $requestId,
        public string $verifiedBy,
        public string $verificationNotes,
        public array $verificationArtefacts,
        public CarbonInterface $verifiedAt,
    ) {}
}
