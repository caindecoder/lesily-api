<?php

namespace App\Companies\OwnershipTransfers\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Companies\OwnershipTransfers\Enums\RejectionReasonCode;

/**
 * The T3/T5 rejection record (UC-1-128, SM-1-05). Both client fields are
 * mandatory at write time by design: the UC's minimal guarantee is that no
 * rejection can reach stored_events without an attributable actor, verbatim
 * prose AND a reason code, so an admin cannot end a transfer unaccountably.
 *
 * Assembled and validated at the delivery boundary via ::validateAndCreate (the
 * standing pattern, s146) — never ::from, which skips rules() entirely.
 */
class RejectOwnershipTransferCommand extends Data
{
    public string $reason;

    public string $rejectionReasonCode;

    /** Auth-resolved at the boundary, not client-supplied. */
    public string $rejectedBy;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        // max:1000 bounds the prose at the boundary exactly as
        // RequestOwnershipTransferCommand bounds its own reason — the column is
        // text, the bound is the contract's (UC-1-019 poison-event precedent).
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'rejectionReasonCode' => ['required', Rule::in(RejectionReasonCode::values())],
            'rejectedBy' => ['required', 'uuid'],
        ];
    }
}
