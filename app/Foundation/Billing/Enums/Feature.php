<?php

namespace App\Foundation\Billing\Enums;

/**
 * Every gatable feature in the platform, defined in code (type-safe, no DB
 * lookup) per foundation-brief § "Plans, Billing & Feature Gating".
 *
 * Plans grant features hierarchically via BillingPlan::features(); add-ons grant
 * extra features via Addon::features(). Deprecated features are kept as cases
 * (never hard-removed per S10b) but granted by no plan or add-on, so the two-gate
 * check fails closed on them.
 */
enum Feature: string
{
    case EmployeeDirectory = 'employee_directory';
    case TwoFactorAuth = 'two_factor_auth';
}
