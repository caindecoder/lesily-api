<?php

namespace App\Companies\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The admin case-queue query input (UC-1-127): whitelisted filter/pagination
 * params per api-conventions §9 + §10.1. No sort key — ordering is fixed
 * newest-first, so a `?sort=` is refused like any unknown key. Unknown query
 * keys are refused because Data would silently ignore them (InvoiceFilters /
 * MembershipFilters precedent). Snake query keys map onto camelCase
 * properties (conv-plan §2.17).
 */
class OwnershipTransferCaseAdminFilters extends Data
{
    /**
     * The query keys this endpoint accepts (api-conventions §10.1 whitelist).
     *
     * @var list<string>
     */
    private const array QUERY_KEY_WHITELIST = ['status', 'company_id', 'page', 'per_page'];

    public ?OwnershipTransferCaseStatus $status = null;

    #[MapInputName('company_id')]
    #[OpaqueString('a list filter; an unknown value yields an empty list')]
    public ?string $companyId = null;

    public ?int $page = null;

    #[MapInputName('per_page')]
    public ?int $perPage = null;

    public static function fromQuery(Request $request): static
    {
        $unknownKeys = array_diff(array_keys($request->query()), self::QUERY_KEY_WHITELIST);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages(
                array_fill_keys($unknownKeys, 'This filter is not supported.')
            );
        }

        return static::validateAndCreate($request->query());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        // filled: an empty string skips every non-implicit rule (OQ-1-163) —
        // only an implicit rule can refuse '' at this seam. Listed first so
        // its removal stays test-visible and the empty-value message leads.
        return [
            'status' => ['filled', 'sometimes', 'string', Rule::enum(OwnershipTransferCaseStatus::class)],
            'company_id' => ['filled', 'sometimes', 'uuid'],
            'page' => ['filled', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['filled', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
