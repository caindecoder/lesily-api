<?php

namespace App\Billing\Subscriptions\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The company's subscription-state read (UC-1-041, slice-plans-billing T2).
 * Pure read — no interactor (cd-subscriptions §5, gate I1); the middleware
 * chain is the only guard.
 */
class SubscriptionsController
{
    public function show(): JsonResponse
    {
        return new JsonResponse;
    }
}
