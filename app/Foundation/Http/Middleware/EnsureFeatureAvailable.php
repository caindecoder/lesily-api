<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\Billing\Enums\BillingPlan;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;

/**
 * The billing gate (first of the two gates per api-conventions §11). Denies the
 * request when the current company's plan + active add-ons do not grant the
 * route's feature, pointing at the lowest tier that would. The company context
 * is resolved and validated upstream by AuthorizeCompany (cd-foundation-http
 * step 4) and consumed via $request->company().
 */
class EnsureFeatureAvailable
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $required = Feature::from($feature);
        $company = $request->company();

        if ($company->canUseFeature($required)) {
            return $next($request);
        }

        $upgradePlan = BillingPlan::lowestPlanWith($required);

        if ($upgradePlan === null) {
            Log::warning('Feature gate failed closed: feature offered by no plan or add-on.', [
                'feature' => $required->value,
                'company_id' => $company->getKey(),
            ]);
        }

        throw new FeatureUnavailableException($upgradePlan?->label(), '/settings/billing/upgrade');
    }
}
