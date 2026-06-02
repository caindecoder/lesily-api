<?php

namespace App\Billing\Subscriptions\Projectors;

use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Billing\Subscriptions\Events\SubscriptionTrialStarted;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;

/**
 * Mirrors the Subscription aggregate's lifecycle events into the `subscriptions` read
 * model (ERD-1-03). A new subscription opens a trial scoped to its owning company; the
 * plan is NULL until the company subscribes (CD-1-03).
 */
class SubscriptionProjector extends Projector
{
    public function onSubscriptionTrialStarted(SubscriptionTrialStarted $event): void
    {
        SubscriptionReadModel::query()->create([
            'id' => $event->aggregateRootUuid(),
            'company_id' => $event->companyId,
            'status' => SubscriptionStatus::Trial,
            'trial_ends_at' => $event->trialEndsAt,
        ]);
    }
}
