<?php

namespace App\Billing\Subscriptions\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Companies\Aggregates\Company;
use App\Companies\UseCases\Audit\CompanyAudit;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\UseCases\Commands\ApplyTrialExpirationCommand;

/**
 * Pattern A (coordination-patterns.md §3) — moves a company and its subscription out of
 * trial atomically in one DB::transaction(). Each aggregate emits only its own event; the
 * aggregates' guards make this a no-op when either is no longer in Trial (idempotent /
 * skip-if-subscribed). Recorded events: CompanyTrialExpired (T3) + SubscriptionTrialEnded (T4).
 */
class ApplyTrialExpirationInteractor
{
    public function __construct(protected readonly CompanyAudit $audit) {}

    public function execute(ApplyTrialExpirationCommand $command): void
    {
        DB::transaction(function () use ($command): void {
            $company = Company::retrieve($command->company->getKey())->trialExpire();
            $expired = $company->getRecordedEvents() !== [];
            $company->persist();
            Subscription::retrieve($command->subscription->getKey())->endTrial('trial-expired')->persist();

            if ($expired) {
                $this->audit->trialExpired($command->company);
            }
        });
    }
}
