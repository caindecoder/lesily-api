<?php

namespace App\Billing\Subscriptions\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Expire one company's elapsed trial. Carries both ids the daily `trials:expire` scan
 * already reads per row, so the interactor never re-queries for the subscription.
 *
 * An internal Spatie\LaravelData command (reference_architecture-patterns.md §Commands +
 * CD-1-03): built via ::from() from the scan's own read-model rows, so it carries no
 * rules() (conv-plan §2.15.B.2, amendment AQ).
 */
class ApplyTrialExpirationCommand extends Data
{
    public string $companyId;

    public string $subscriptionId;
}
