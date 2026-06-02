<?php

namespace App\Billing\Subscriptions\Aggregates;

use Carbon\CarbonInterface;
use App\Foundation\EventStore\AggregateRoot;
use App\Billing\Subscriptions\Events\SubscriptionTrialStarted;
use App\Billing\Subscriptions\Exceptions\SubscriptionAlreadyStartedException;

class Subscription extends AggregateRoot
{
    protected bool $trialStarted = false;

    public function startTrial(string $companyId, CarbonInterface $trialEndsAt): static
    {
        if ($this->trialStarted) {
            throw SubscriptionAlreadyStartedException::withId($this->uuid());
        }

        return $this->forCompany($companyId)
            ->recordThat(new SubscriptionTrialStarted(companyId: $companyId, trialEndsAt: $trialEndsAt));
    }

    protected function applySubscriptionTrialStarted(SubscriptionTrialStarted $event): void
    {
        $this->trialStarted = true;
    }
}
