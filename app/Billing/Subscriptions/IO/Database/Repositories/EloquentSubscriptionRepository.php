<?php

namespace App\Billing\Subscriptions\IO\Database\Repositories;

use Carbon\CarbonInterface;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;
use App\Billing\Subscriptions\UseCases\Repositories\SubscriptionRepositoryInterface;

class EloquentSubscriptionRepository implements SubscriptionRepositoryInterface
{
    /**
     * @return array<int, array{companyId: string, subscriptionId: string}>
     */
    public function listExpiringTrials(CarbonInterface $asOf): array
    {
        return SubscriptionReadModel::query()
            ->where('status', SubscriptionStatus::Trial)
            ->where('trial_ends_at', '<', $asOf)
            ->get()
            ->map(fn (SubscriptionReadModel $subscription): array => [
                'companyId' => $subscription->company_id,
                'subscriptionId' => $subscription->getKey(),
            ])
            ->all();
    }
}
