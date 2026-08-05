<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Open-case payload for the support-assisted ownership-transfer workflow
 * (UC-1-140; slice-11R spec §4). Built via ::validateAndCreate only — never
 * ::from (amendment U). newOwnerUserId is optional here and becomes mandatory
 * at verify (UC-1-113); when supplied it must name a live member of the named
 * company — case-open is advisory intake, so the membership check is a
 * validation rule, not a locked guard (the transfer itself re-validates under
 * lock at execute).
 */
class OpenOwnershipTransferCaseCommand extends Data
{
    public string $companyId;

    public string $requestedContext;

    public ?string $newOwnerUserId = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'companyId' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'requestedContext' => ['required', 'string', 'max:1000'],
            'newOwnerUserId' => [
                'nullable',
                'uuid',
                Rule::exists('company_user', 'user_id')
                    ->where('company_id', $context->payload['companyId'] ?? null),
            ],
        ];
    }
}
