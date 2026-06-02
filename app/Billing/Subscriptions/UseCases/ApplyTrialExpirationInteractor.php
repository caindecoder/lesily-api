<?php

namespace App\Billing\Subscriptions\UseCases;

use Illuminate\Support\Facades\DB;
use App\Companies\Aggregates\Company;
use App\Billing\Subscriptions\Aggregates\Subscription;

/**
 * Pattern A (coordination-patterns.md §3) — moves a company and its subscription out of
 * trial atomically in one DB::transaction(). Each aggregate emits only its own event; the
 * aggregates' guards make this a no-op when either is no longer in Trial (idempotent /
 * skip-if-subscribed). Recorded events: CompanyTrialExpired (T3) + SubscriptionTrialEnded (T4).
 */
class ApplyTrialExpirationInteractor
{
    public function execute(ApplyTrialExpirationCommand $command): void
    {
        DB::transaction(function () use ($command): void {
            Company::retrieve($command->companyId)->trialExpire()->persist();
            Subscription::retrieve($command->subscriptionId)->endTrial('trial-expired')->persist();
        });
    }
}
