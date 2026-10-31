<?php

namespace App\Billing\Subscriptions\IO\Database\Repositories;

use Carbon\CarbonInterface;
use App\Companies\Entities\Company;
use App\Billing\Subscriptions\Enums\SubscriptionStatus;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;
use App\Billing\Subscriptions\UseCases\Repositories\SubscriptionRepositoryInterface;

class EloquentSubscriptionRepository implements SubscriptionRepositoryInterface
{
    /**
     * @return array<int, array{company: Company, subscription: SubscriptionReadModel}>
     */
    public function listExpiringTrials(CarbonInterface $asOf): array
    {
        return SubscriptionReadModel::query()
            ->with('company')
            ->where('status', SubscriptionStatus::Trial)
            ->where('trial_ends_at', '<', $asOf)
            ->get()
            ->map(fn (SubscriptionReadModel $subscription): array => [
                'company' => $subscription->company,
                'subscription' => $subscription,
            ])
            ->all();
    }
}
