<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Reject payload for an ownership-transfer case (UC-1-128; slice-11R spec
 * §5/§6). Built via ::validateAndCreate only — never ::from (amendment U).
 * caseId is server-sourced from the route; no exists rule on it — the
 * interactor's locked find owns 404.
 */
class RejectOwnershipTransferCaseCommand extends Data
{
    public string $caseId;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'caseId' => ['required', 'uuid'],
        ];
    }
}
