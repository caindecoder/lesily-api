<?php

namespace App\Billing\Subscriptions\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The subscription's trial ended (SM-1-03 T4, Trial → Cancelled). `source` discriminates
 * why (trial-expired / switched-to-free / owner-cancelled). `endedAt` is typed
 * CarbonInterface to survive spatie's denormalise-to-mutable-Carbon on replay.
 */
class SubscriptionTrialEnded extends ShouldBeStored
{
    public function __construct(
        public string $source,
        public CarbonInterface $endedAt,
    ) {}
}
