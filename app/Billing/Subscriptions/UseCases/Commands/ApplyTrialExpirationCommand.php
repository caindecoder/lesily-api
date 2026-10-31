<?php

namespace App\Billing\Subscriptions\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use App\Billing\Subscriptions\Entities\Subscription;

/**
 * Expire one company's elapsed trial. Carries the company and subscription the daily
 * `trials:expire` scan already loaded, so the interactor never re-queries for them.
 *
 * An internal Spatie\LaravelData command (reference_architecture-patterns.md §Commands +
 * CD-1-03): built via ::from() from the scan's loaded models, so it carries no
 * rules() (conv-plan §2.15.B.2, amendment AQ).
 */
class ApplyTrialExpirationCommand extends Data
{
    public Company $company;

    public Subscription $subscription;
}
