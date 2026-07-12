<?php

namespace App\Billing\Subscriptions\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The plan catalog (UC-1-042 partial — the plan-picker read). Enum-derived
 * static read: no interactor (cd-subscriptions §5, simplicity-gate I1) —
 * the billing.view middleware gate is the only guard.
 */
class PlansController
{
    public function index(): JsonResponse
    {
        return response()->json([]);
    }
}
