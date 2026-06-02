<?php

namespace App\Billing\Subscriptions\UseCases;

/**
 * Expire one company's elapsed trial. Carries both ids the daily `trials:expire` scan
 * already reads per row, so the interactor never re-queries for the subscription.
 */
class ApplyTrialExpirationCommand
{
    public function __construct(
        public string $companyId,
        public string $subscriptionId,
    ) {}
}
