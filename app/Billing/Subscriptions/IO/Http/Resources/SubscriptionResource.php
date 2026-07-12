<?php

namespace App\Billing\Subscriptions\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Companies\Entities\CompanyAddon;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Billing\Subscriptions\Entities\Subscription;

/**
 * The company's subscription state — the full designed B5 contract
 * (api-1-subscriptions §2). The plan is read from the company read model
 * (companies.current_billing_plan, NULL during trial — s066), never the
 * subscriptions table; add-ons from company_addons as addon codes.
 *
 * @property-read Subscription $resource
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $company = $request->company();

        return [
            'id' => $this->resource->getKey(),
            'status' => $this->resource->status->value,
            'plan' => $company->current_billing_plan?->value,
            'trial_ends_at' => $this->resource->trial_ends_at?->toISOString(),
            'add_ons' => $company->addons
                ->map(fn (CompanyAddon $companyAddon): string => $companyAddon->addon->value)
                ->values()
                ->all(),
            'cancelled_at' => $this->resource->cancelled_at?->toISOString(),
            'ended_source' => $this->resource->ended_source,
            // Present as documented nulls until their writers land with the
            // payment slice (B5 contract keys, api-1-subscriptions §2).
            'billing_cycle' => null,
            'period_start' => null,
            'period_end' => null,
            'next_charge_at' => null,
            'payment_method_summary' => null,
            'past_due_status' => null,
        ];
    }
}
