<?php

namespace App\Companies\OwnershipTransfers\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * SM-1-05 T8 + T9 — a transfer outlived its SLA window and the daily scan ended
 * it. One event class for both windows, discriminated by `source`, the same
 * shape `OwnershipTransferRejected` and `OwnershipTransferCancelled` use.
 *
 * `CarbonInterface`, not `CarbonImmutable`: spatie denormalizes replayed
 * timestamps to mutable Carbon, so the stricter type breaks replay. Every field
 * is spelled INLINE rather than behind a `@phpstan-type` alias, which the
 * serializer resolves as a class — the scar from s149 C3, where an event wrote
 * successfully but could never be replayed.
 *
 * `source` stays a plain string even though `TransitionSource` is now the single
 * source of truth at every call site (ruling J6): `stored_events` is append-only,
 * so a class-typed payload property is an event-versioning change (OQ-1-108)
 * rather than a refactor. The aggregate derives a case and records `->value`.
 *
 * `expiredAfterDays` is the breached POLICY window (30 or 14), not measured
 * elapsed time (ruling M2) — the interactor reads it from config by the
 * pre-transition state, so a scan that runs late still records the SLA it broke.
 */
class OwnershipTransferExpired extends ShouldBeStored
{
    public function __construct(
        public string $requestId,
        public string $source,
        public int $expiredAfterDays,
        public CarbonInterface $expiredAt,
    ) {}
}
