<?php

namespace App\Billing\Subscriptions\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\Billing\Enums\BillingPlan;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The four-tier plan catalog (UC-1-042 partial) — enum-derived rows in the
 * non-paginated {data: [...]} envelope. Employee minimums are deliberately
 * absent: §10 min-5 is ToS-only, never advertised (R9). Custom plans are
 * per-company manual creations and never appear here.
 *
 * @property-read Collection<int, BillingPlan> $collection
 */
class PlanCollection extends ResourceCollection
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        return $this->collection
            ->map(fn (BillingPlan $plan): array => [
                'code' => $plan->value,
                'label' => $plan->label(),
                'features' => array_map(fn (Feature $feature): string => $feature->value, $plan->features()),
                'monthly_price_cents' => $plan->monthlyPriceCents(),
                'annual_price_cents' => $plan->annualPriceCents(),
                'currency' => 'LKR',
            ])
            ->all();
    }
}
