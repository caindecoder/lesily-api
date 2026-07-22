<?php

namespace App\Companies\OwnershipTransfers\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Genesis of the OwnershipTransferRequest stream (SM-1-05 T1: [*] → Requested).
 * Payload per CD-1-05 §3; requestChannel literals per the s146 F2 re-pin
 * (app | support_ticket). CarbonInterface — spatie denormalizes replayed
 * timestamps to mutable Carbon (trial-lifecycle #37 lesson).
 */
class OwnershipTransferRequested extends ShouldBeStored
{
    public function __construct(
        public string $requestId,
        public string $companyId,
        public string $currentOwnerUserId,
        public string $targetUserId,
        public string $reason,
        public string $requestChannel,
        public CarbonInterface $requestedAt,
    ) {}
}
