<?php

namespace App\Companies\OwnershipTransfers\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The T6/T7 withdrawal record (UC-1-114 / UC-1-115, SM-1-05).
 *
 * `reason` is OPTIONAL here, unlike its reject counterpart: an admin ending
 * someone else's transfer owes them an explanation, whereas a requester ending
 * their own owes nobody one — ERD-1-05 carries `cancellation_reason` nullable
 * and the event payload declares `reason?` to match.
 *
 * Assembled and validated at the delivery boundary via ::validateAndCreate (the
 * standing pattern, s146) — never ::from, which skips rules() entirely.
 */
class WithdrawOwnershipTransferCommand extends Data
{
    public ?string $reason;

    /** Auth-resolved at the boundary, not client-supplied. */
    public string $cancelledBy;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        // max:1000 bounds the prose at the boundary exactly as its reject and
        // request siblings do — the column is text, the bound is the contract's
        // (UC-1-019 poison-event precedent).
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
            'cancelledBy' => ['required', 'uuid'],
        ];
    }
}
