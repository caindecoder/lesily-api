<?php

namespace App\Companies\OwnershipTransfers\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * One row of the T8/T9 expiry scan (UC-1-116 / UC-1-117, SM-1-05).
 *
 * Carries no actor: the scheduler is the actor, so there is no user to record —
 * the deliberate contrast with its reject and withdraw siblings, which both name
 * the human who ended the transfer.
 *
 * `expiredAfterDays` is the breached POLICY window (ruling M2), resolved from
 * config by the interactor against the pre-transition state. It lives on the
 * Command rather than being derived inside the aggregate so the aggregate never
 * reads config, and so a scan that runs late still records the SLA that was
 * broken rather than however long the row happened to sit.
 *
 * Assembled via ::validateAndCreate only — never ::from, which skips rules()
 * (amendment U). The rules are unreachable from the console, which reads real
 * rows: they guard future callers, exactly as `ApplyTrialExpirationCommand`'s do.
 */
class ExpireOwnershipTransferCommand extends Data
{
    public string $requestId;

    public int $expiredAfterDays;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'requestId' => ['required', 'uuid'],
            'expiredAfterDays' => ['required', 'integer', 'min:1'],
        ];
    }
}
