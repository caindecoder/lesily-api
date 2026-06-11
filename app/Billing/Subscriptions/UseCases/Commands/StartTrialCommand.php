<?php

namespace App\Billing\Subscriptions\UseCases\Commands;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Start a company's 30-day trial subscription. trialEndsAt is supplied by the
 * orchestrating interactor so the Subscription stays consistent with the Company
 * genesis it accompanies (both anchor to the same registration instant).
 *
 * A Spatie\LaravelData command (reference_architecture-patterns.md §Commands —
 * aggregate-takes-Command standing convention, locked company-onboarding #02) —
 * constructed via ::validateAndCreate() so inputs are validated at the use-case
 * boundary.
 */
class StartTrialCommand extends Data
{
    public string $companyId;

    public CarbonImmutable $trialEndsAt;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'companyId' => ['uuid'],
            'trialEndsAt' => ['date'],
        ];
    }
}
