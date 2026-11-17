<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Foundation\UseCases\Commands\QueryCommand;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The admin case-queue query input (UC-1-127): whitelisted filter/pagination
 * params per api-conventions §9 + §10.1. No sort key — ordering is fixed
 * newest-first, so a `?sort=` is refused like any unknown key. Unknown query
 * keys are refused because Data would silently ignore them (ListInvoicesCommand /
 * ListMembershipsCommand precedent). Snake query keys map onto camelCase
 * properties (conv-plan §2.17).
 */
class ReviewOwnershipTransferCaseQueueCommand extends QueryCommand
{
    /**
     * The query keys this endpoint accepts (api-conventions §10.1 whitelist).
     *
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['status', 'company_id', 'page', 'per_page'];

    public ?OwnershipTransferCaseStatus $status = null;

    #[MapInputName('company_id')]
    #[OpaqueString('a list filter; an unknown value yields an empty list')]
    public ?string $companyId = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        // filled: an empty string skips every non-implicit rule (OQ-1-163) —
        // only an implicit rule can refuse '' at this seam. Listed first so
        // its removal stays test-visible and the empty-value message leads.
        return [
            ...parent::rules($context),
            'status' => ['filled', 'sometimes', 'string', Rule::enum(OwnershipTransferCaseStatus::class)],
            'company_id' => ['filled', 'sometimes', 'uuid'],
        ];
    }
}
