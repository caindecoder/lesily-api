<?php

namespace App\Billing\Subscriptions\UseCases\Interactors;

use Carbon\CarbonInterface;
use App\Billing\Subscriptions\UseCases\Commands\ApplyTrialExpirationCommand;
use App\Billing\Subscriptions\UseCases\Repositories\SubscriptionRepositoryInterface;

/**
 * Daily scan that ends every elapsed trial. The boundary (the `trials:expire` command) hands
 * it the cutoff; it reads the (companyId, subscriptionId) pairs from the repository and runs
 * the Pattern-A ApplyTrialExpirationInteractor once per pair. Delivery stays a thin appendix.
 */
class ExpireElapsedTrialsInteractor
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptions,
        private ApplyTrialExpirationInteractor $applyExpiry,
    ) {}

    public function execute(CarbonInterface $asOf): void
    {
        foreach ($this->subscriptions->listExpiringTrials($asOf) as $expiringTrial) {
            $this->applyExpiry->execute(ApplyTrialExpirationCommand::validateAndCreate($expiringTrial));
        }
    }
}
