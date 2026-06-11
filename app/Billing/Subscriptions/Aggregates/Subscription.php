<?php

namespace App\Billing\Subscriptions\Aggregates;

use Carbon\CarbonImmutable;
use App\Foundation\EventStore\AggregateRoot;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use App\Billing\Subscriptions\Events\SubscriptionTrialEnded;
use App\Billing\Subscriptions\Events\SubscriptionTrialStarted;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;
use App\Billing\Subscriptions\Exceptions\SubscriptionAlreadyStartedException;

class Subscription extends AggregateRoot
{
    protected ?SubscriptionStatus $status = null;

    public function startTrial(StartTrialCommand $command): static
    {
        if ($this->status !== null) {
            throw SubscriptionAlreadyStartedException::withId($this->uuid());
        }

        return $this->forCompany($command->companyId)
            ->recordThat(new SubscriptionTrialStarted(
                companyId: $command->companyId,
                trialEndsAt: $command->trialEndsAt,
            ));
    }

    public function endTrial(string $source): static
    {
        if ($this->status !== SubscriptionStatus::Trial) {
            return $this;
        }

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
