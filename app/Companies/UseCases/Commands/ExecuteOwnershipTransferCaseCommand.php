<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Execute payload for a VERIFIED ownership-transfer case (UC-1-093; slice-11R
 * spec §5/§6). Built at the HTTP boundary via ::validateAndCreate (amendment
 * AQ). caseId is server-sourced from the route; no exists rule on it — the
 * interactor's locked find owns 404. The request carries no body: everything
 * the execution needs is already on the verified case row.
 */
class ExecuteOwnershipTransferCaseCommand extends Data
{
    #[OpaqueString('the case is read once, locked, inside the transaction')]
    public string $caseId;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'caseId' => ['uuid'],
        ];
    }
}
