<?php

namespace App\Billing\Subscriptions\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Expire one company's elapsed trial. Carries both ids the daily `trials:expire` scan
 * already reads per row, so the interactor never re-queries for the subscription.
 *
 * A Spatie\LaravelData command (reference_architecture-patterns.md §Commands + CD-1-03) —
 * constructed via ::validateAndCreate() so inputs are validated at the use-case boundary.
 */
class ApplyTrialExpirationCommand extends Data
{
    public string $companyId;

    public string $subscriptionId;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        // `required` is inferred from the non-nullable string properties; only the
        // uuid format needs an explicit rule.
        return [
            'companyId' => ['uuid'],
            'subscriptionId' => ['uuid'],
        ];
    }
}
