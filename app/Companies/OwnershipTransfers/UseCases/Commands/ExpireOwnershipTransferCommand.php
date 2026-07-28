<?php

namespace App\Companies\OwnershipTransfers\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;

/**
 * One row of the T8/T9 expiry scan (UC-1-116 / UC-1-117, SM-1-05).
 *
 * Carries no actor: the scheduler is the actor, so there is no user to record —
 * the deliberate contrast with its reject and withdraw siblings, which both name
 * the human who ended the transfer.
 *
 * `expectedStatus` is the state the SCAN saw when it selected this row (ruling
 * O1). It exists because the two halves of the scan read different tables at
 * different instants: selection queries the projection, the transition replays
 * the stream. Anything that moves the row in between — an admin verifying, the
 * owner withdrawing — makes the selection stale, and the aggregate has no way to
 * notice on its own. Carrying what the scan saw lets it refuse the disagreement,
 * which is what UC-1-116 error flow 4b and SM-1-05 §4's "state STILL `Requested`"
 * ask for. It replaces a `requestId` field nothing read: the aggregate takes its
 * own identity from `uuid()`, and no sibling command carries one.
 *
 * `expiredAfterDays` is the breached POLICY window (ruling M2), resolved from
 * config by the interactor against that same selected state. It lives on the
 * Command rather than being derived inside the aggregate so the aggregate never
 * reads config, and so a scan that runs late still records the SLA that was
 * broken rather than however long the row happened to sit. Both fields now
 * derive from ONE status read, so the event cannot contradict itself.
 *
 * Assembled via ::validateAndCreate only — never ::from, which skips rules()
 * (amendment U). The rules are unreachable from the console, which reads real
 * rows: they guard future callers, exactly as `ApplyTrialExpirationCommand`'s do
 * — and, like that precedent's, they are pinned by their own rejection row.
 */
class ExpireOwnershipTransferCommand extends Data
{
    public OwnershipTransferStatus $expectedStatus;

    public int $expiredAfterDays;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'expectedStatus' => ['required', Rule::enum(OwnershipTransferStatus::class)],
            'expiredAfterDays' => ['required', 'integer', 'min:1'],
        ];
    }
}
