<?php

namespace App\Companies\OwnershipTransfers\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-05 T3 (Requested → Rejected, `pre-verification`) and T5 (Verified →
 * Rejected, `execution-time`). Terminal — the aggregate records nothing after it.
 *
 * `source` names WHICH window ended the transfer, the same discriminator idiom
 * the sibling Cancelled and Expired events use, and the same one the built
 * Invoice events use for their own paired transitions (`void_source`
 * dispute-pre-payment | admin-correction). Hyphenated literals per ERD-1-05 §3.
 *
 * The payload carries its FULL designed shape from the first write rather than
 * growing later: stored_events is append-only, so widening a payload in place is
 * an event-versioning problem (OQ-1-108), not a refactor.
 *
 * Every field is a scalar and stays spelled out here — array shapes in this
 * module are written INLINE and never behind a `@phpstan-type` alias, which at
 * s149 made the serializer resolve a payload field as a non-existent class, so
 * the event wrote but could never be replayed (C3).
 */
class OwnershipTransferRejected extends ShouldBeStored
{
    public function __construct(
        public string $requestId,
        public string $rejectedBy,
        public string $rejectionReason,
        public string $rejectionReasonCode,
        public string $source,
        public CarbonInterface $rejectedAt,
    ) {}
}
