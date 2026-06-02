<?php

namespace App\Billing\Subscriptions\Enums;

/**
 * SM-1-03 lifecycle state for a subscription. Only the states this slice reaches are
 * defined; later states (Active, PastDue, Cancelled, …) land with the increments that
 * first write them.
 */
enum SubscriptionStatus: string
{
    case Trial = 'trial';
}
