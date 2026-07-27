<?php

namespace App\Companies\OwnershipTransfers\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-05 T6 (Requested → Cancelled, `pre-verification`) and T7 (Verified →
 * Cancelled, `post-verification`). Terminal — the aggregate records nothing
 * after it.
 *
 * `source` names WHICH window the requester withdrew in, the same discriminator
 * idiom the sibling Rejected and Expired events use. It is a plain `string`
 * carrying a `TransitionSource` value rather than the enum itself: stored_events
 * is append-only and no built event carries a class-typed property (ruling J6).
 *
 * `reason` is nullable where its rejection counterpart is mandatory — a
 * requester ending their own transfer owes no explanation, and ERD-1-05 carries
 * `cancellation_reason` nullable to match.
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
class OwnershipTransferCancelled extends ShouldBeStored
{
    public function __construct(
        public string $requestId,
        public string $cancelledBy,
        public ?string $reason,
        public string $source,
        public CarbonInterface $cancelledAt,
    ) {}
}
