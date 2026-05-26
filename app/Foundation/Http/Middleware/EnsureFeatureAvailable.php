<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use App\Models\Company;
use Illuminate\Http\Request;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\Billing\Enums\BillingPlan;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Http\Exceptions\FeatureUnavailableException;

class EnsureFeatureAvailable
{
    public function handle(Request $request, Closure $next, string $featureValue): Response
    {
        $feature = Feature::from($featureValue);
        $company = $this->resolveCompany($request);

        if ($company === null || ! $company->canUseFeature($feature)) {
            $requiredPlan = $this->minPlanFor($feature);
            throw new FeatureUnavailableException(
                upgradeTo: $requiredPlan->value,
                upgradeUrl: '/billing/upgrade',
            );
        }

        return $next($request);
    }

    private function resolveCompany(Request $request): ?Company
    {
        $companyId = $request->header('X-Company-Id');
        if (! is_string($companyId) || $companyId === '') {
            return null;
        }

        return Company::query()->find($companyId);
    }

    private function minPlanFor(Feature $feature): BillingPlan
    {
        foreach (BillingPlan::cases() as $plan) {
            if (in_array($feature, $plan->features(), strict: true)) {
                return $plan;
            }
        }

        return BillingPlan::Enterprise;
    }
}
