<?php

namespace App\Billing\Subscriptions\Aggregates;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Foundation\EventStore\AggregateRoot;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use App\Billing\Subscriptions\Events\SubscriptionTrialEnded;
use App\Billing\Subscriptions\Events\SubscriptionTrialStarted;
use App\Billing\Subscriptions\Exceptions\SubscriptionAlreadyStartedException;

class Subscription extends AggregateRoot
{
    protected ?SubscriptionStatus $status = null;

    public function startTrial(string $companyId, CarbonInterface $trialEndsAt): static
    {
        if ($this->status !== null) {
            throw SubscriptionAlreadyStartedException::withId($this->uuid());
        }

        return $this->forCompany($companyId)
            ->recordThat(new SubscriptionTrialStarted(companyId: $companyId, trialEndsAt: $trialEndsAt));
    }

    public function endTrial(string $source): static
    {
        return $this->recordThat(new SubscriptionTrialEnded(source: $source, endedAt: CarbonImmutable::now()));
    }

    protected function applySubscriptionTrialStarted(SubscriptionTrialStarted $event): void
    {
        $this->status = SubscriptionStatus::Trial;
    }

    protected function applySubscriptionTrialEnded(SubscriptionTrialEnded $event): void
    {
        $this->status = SubscriptionStatus::Cancelled;
    }
}
