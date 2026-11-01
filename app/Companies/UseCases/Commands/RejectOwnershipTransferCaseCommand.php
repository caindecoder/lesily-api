<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use App\Companies\Enums\RejectionReasonCode;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Reject payload for an ownership-transfer case (UC-1-128; slice-11R spec
 * §5/§6). Built at the HTTP boundary via ::validateAndCreate (amendment AQ).
 * caseId is server-sourced from the route; no exists rule on it — the
 * interactor's locked find owns 404.
 */
class RejectOwnershipTransferCaseCommand extends Data
{
    #[OpaqueString('the case is read once, locked, inside the transaction')]
    public string $caseId;

    public string $reason;

    public RejectionReasonCode $reasonCode;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'caseId' => ['uuid'],
            'reason' => ['required', 'string', 'max:1000'],
            'reasonCode' => ['required', Rule::enum(RejectionReasonCode::class)],
        ];
    }
}
