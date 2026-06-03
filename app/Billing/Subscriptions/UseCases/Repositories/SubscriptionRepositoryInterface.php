<?php

namespace App\Billing\Subscriptions\UseCases\Repositories;

use Carbon\CarbonInterface;

interface SubscriptionRepositoryInterface
{
    /**
     * The (companyId, subscriptionId) pairs whose trial has elapsed before $asOf — each pair
     * is everything the Pattern-A ApplyTrialExpirationCommand needs, so the daily scan never
     * re-queries per row.
     *
     * @return array<int, array{companyId: string, subscriptionId: string}>
     */
    public function listExpiringTrials(CarbonInterface $asOf): array;
}
