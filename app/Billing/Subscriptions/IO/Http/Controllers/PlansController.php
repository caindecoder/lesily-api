<?php

namespace App\Billing\Subscriptions\IO\Http\Controllers;

use App\Foundation\Billing\Enums\BillingPlan;
use App\Billing\Subscriptions\IO\Http\Resources\PlanCollection;

/**
 * The plan catalog (UC-1-042 partial — the plan-picker read). Enum-derived
 * static read: no interactor (cd-subscriptions §5, simplicity-gate I1) —
 * the billing.view middleware gate is the only guard.
 */
class PlansController
{
    public function index(): PlanCollection
    {
        return new PlanCollection(collect(BillingPlan::cases()));
    }
}
