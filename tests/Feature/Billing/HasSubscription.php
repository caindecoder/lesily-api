<?php

namespace Tests\Feature\Billing;

use App\Companies\Entities\Company;
use App\Foundation\Billing\Enums\BillingPlan;

trait HasSubscription
{
    protected function putOnPlan(Company $company, BillingPlan $plan): void
    {
        $company->update(['current_billing_plan' => $plan]);
    }
}
