<?php

namespace App\Companies\OwnershipTransfers\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The T4 execution record (UC-1-093, SM-1-05). Carries `reason` and nothing
 * else from the client: execute RE-VALIDATES, it does not re-enter (ruling P3).
 *
 * The target was fixed at T1 and the verification notes were captured and made
 * mandatory at T2, so neither is accepted here — they are carried forward onto
 * the executed event instead. UC-1-093's Main Flow 2-4 used to describe the
 * admin selecting the target and typing the notes; that predates the request
 * lifecycle and was reconciled at the s159 pre-flight.
 *
 * Assembled and validated at the delivery boundary via ::validateAndCreate (the
 * standing pattern, s146) — never ::from, which skips rules() entirely.
 */
class ExecuteOwnershipTransferCommand extends Data
{
    public string $reason;

    /** Auth-resolved at the boundary, not client-supplied. */
    public string $executedBy;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        // max:1000 bounds the prose exactly as the request and rejection
        // commands bound theirs — the column is text, the bound is the
        // contract's (UC-1-019 poison-event precedent).
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'executedBy' => ['required', 'uuid'],
        ];
    }
}
