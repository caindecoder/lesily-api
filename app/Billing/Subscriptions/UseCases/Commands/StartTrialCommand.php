<?php

namespace App\Billing\Subscriptions\UseCases\Commands;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;

/**
 * Start a company's 30-day trial subscription. trialEndsAt is supplied by the
 * orchestrating interactor, computed to the same 30-day window as the Company
 * genesis it accompanies (the subscription date drives the trials:expire scan;
 * the company date is pinned equal in the register acceptance test).
 *
 * An internal Spatie\LaravelData command (reference_architecture-patterns.md
 * §Commands — aggregate-takes-Command standing convention, locked
 * company-onboarding #02): built via ::from() by the interactor with
 * server-minted values, so it carries no rules() (conv-plan §2.15.B.2).
 */
class StartTrialCommand extends Data
{
    public Company $company;

    public CarbonImmutable $trialEndsAt;
}
