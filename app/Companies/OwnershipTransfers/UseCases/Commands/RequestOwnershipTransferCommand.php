<?php

namespace App\Companies\OwnershipTransfers\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Genesis payload for the OwnershipTransferRequest aggregate (UC-1-028, SM-1-05
 * T1). ONE command for HTTP shape + aggregate boundary (CD-1-05 §2): the
 * interactor injects the auth-resolved companyId/currentOwnerUserId alongside
 * the three client fields — nothing is minted in between, so the invite-style
 * two-command split is not needed (Task-1 design D-a).
 */
class RequestOwnershipTransferCommand extends Data
{
    public string $companyId;

    public string $currentOwnerUserId;

    public string $targetUserId;

    public string $reason;

    public string $requestChannel;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        // max:1000 bounds the reason at the command boundary (UC-1-019 poison-event
        // precedent — the column is text, the bound is the contract's). Channel
        // literals per the s146 F2 re-pin: app | support_ticket.
        return [
            'companyId' => ['required', 'uuid'],
            'currentOwnerUserId' => ['required', 'uuid'],
            'targetUserId' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
            'requestChannel' => ['required', 'string', 'in:app,support_ticket'],
        ];
    }
}
