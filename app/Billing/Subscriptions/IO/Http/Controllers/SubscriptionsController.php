<?php

namespace App\Billing\Subscriptions\IO\Http\Controllers;

use Illuminate\Http\Request;
use App\Billing\Subscriptions\Entities\Subscription;
use App\Billing\Subscriptions\IO\Http\Resources\SubscriptionResource;

/**
 * The company's subscription-state read (UC-1-041, slice-plans-billing T2).
 * Pure read — no interactor (cd-subscriptions §5, gate I1); the middleware
 * chain is the only guard. Singular fetch: every company has exactly one
 * Subscription, projected at genesis (api-1-subscriptions §2) — firstOrFail
 * backstops that invariant.
 */
class SubscriptionsController
{
    public function show(Request $request): SubscriptionResource
    {
        return new SubscriptionResource(
            Subscription::query()
                ->where('company_id', $request->company()->getKey())
                ->firstOrFail(),
        );
    }
}
