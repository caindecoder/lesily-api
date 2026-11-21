<?php

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Billing\Subscriptions\UseCases\Commands\StartTrialCommand;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;

trait HasSubscription
{
    protected function putOnPlan(Company $company, BillingPlan $plan): void
    {
        $company->update(['current_billing_plan' => $plan]);
    }

    protected function putOnTrial(Company $company, CarbonImmutable $endsAt): void
    {
        $company->update(['current_billing_plan' => null, 'trial_ends_at' => $endsAt]);
    }

    protected function subscriptionOf(Company $company): SubscriptionReadModel
    {
        return SubscriptionReadModel::query()->where('company_id', $company->getKey())->sole();
    }

    /**
     * A trial subscription projected for $company through the domain seam —
     * the genesis event path production takes (StartTrialTest precedent),
     * never a direct row write.
     */
    protected function aTrialSubscriptionFor(Company $company, CarbonImmutable $trialEndsAt): SubscriptionReadModel
    {
        $subscriptionId = (string) Str::uuid();

        Subscription::retrieve($subscriptionId)
            ->startTrial(StartTrialCommand::from([
                'company' => $company,
                'trialEndsAt' => $trialEndsAt,
            ]))
            ->persist();

        return SubscriptionReadModel::findOrFail($subscriptionId);
    }

    /**
     * A subscription whose trial ran out — started and ended through the domain
     * seam, exactly as the trials:expire scan does (ended_source 'trial-expired',
     * cancelled_at stamped from the travelled clock).
     */
    protected function anEndedTrialSubscriptionFor(Company $company, CarbonImmutable $trialEndsAt): SubscriptionReadModel
    {
        $subscription = $this->aTrialSubscriptionFor($company, $trialEndsAt);

        Subscription::retrieve($subscription->getKey())
            ->endTrial('trial-expired')
            ->persist();

        return $subscription->refresh();
    }
}
