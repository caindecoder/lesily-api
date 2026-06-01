<?php

namespace App\Billing\Subscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class SubscriptionTrialStarted extends ShouldBeStored
{
    public function __construct(
        public string $companyId,
        public int $trialDays,
    ) {}
}
