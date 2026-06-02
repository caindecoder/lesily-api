<?php

namespace App\Billing\Subscriptions\Events;

use Carbon\CarbonInterface;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * trialEndsAt mirrors the owning company's trial window (CD-1-03). Typed
 * CarbonInterface so it survives spatie's denormalise-to-mutable-Carbon on replay.
 */
class SubscriptionTrialStarted extends ShouldBeStored
{
    public function __construct(
        public string $companyId,
        public CarbonInterface $trialEndsAt,
    ) {}
}
