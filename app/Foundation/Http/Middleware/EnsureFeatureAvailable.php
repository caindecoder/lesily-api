<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\Billing\Enums\BillingPlan;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;
use App\Foundation\Http\Middleware\Concerns\ResolvesCompanyContext;

/**
 * The billing gate (first of the two gates per api-conventions §11). Resolves the
 * current company and denies the request when the company's plan + active add-ons
 * do not grant the route's feature, pointing at the lowest tier that would.
 */
class EnsureFeatureAvailable
{
    use ResolvesCompanyContext;

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $required = Feature::from($feature);
        $company = $this->resolveCompany($request);

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
