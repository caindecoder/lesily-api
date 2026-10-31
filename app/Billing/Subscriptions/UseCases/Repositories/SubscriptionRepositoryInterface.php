<?php

namespace App\Billing\Subscriptions\UseCases\Repositories;

use Carbon\CarbonInterface;
use App\Companies\Entities\Company;
use App\Billing\Subscriptions\Entities\Subscription;

interface SubscriptionRepositoryInterface
{
    /**
     * The (company, subscription) pairs whose trial has elapsed before $asOf, loaded in one
     * query — each pair is everything the Pattern-A ApplyTrialExpirationCommand needs, so the
     * daily scan never re-queries per row.
     *
     * @return array<int, array{company: Company, subscription: Subscription}>
     */
    public function listExpiringTrials(CarbonInterface $asOf): array;
}
