<?php

namespace App\Companies\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The admin case-queue query input (UC-1-127): whitelisted filter/pagination
 * params per api-conventions §9 + §10.1. No sort key — ordering is fixed
 * newest-first, so a `?sort=` is refused like any unknown key. Unknown query
 * keys are refused because Data would silently ignore them (InvoiceFilters /
 * MembershipFilters precedent). Properties spell the wire keys verbatim
 * (conv-plan §2.17).
 */
class OwnershipTransferCaseAdminFilters extends Data
{
    /**
     * The query keys this endpoint accepts (api-conventions §10.1 whitelist).
     *
     * @var list<string>
     */
    private const array QUERY_KEY_WHITELIST = ['status', 'company_id', 'page', 'per_page'];

    public ?string $status = null;

    public ?string $company_id = null;

    public ?int $page = null;

    public ?int $per_page = null;

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
        return [
            // filled: an empty string skips every non-implicit rule (OQ-1-163),
            // so only an implicit rule can refuse '' at the DTO seam.
            'status' => ['sometimes', 'filled', 'string', Rule::enum(OwnershipTransferCaseStatus::class)],
            'company_id' => ['sometimes', 'filled', 'uuid'],
            'page' => ['sometimes', 'filled', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'filled', 'integer', 'min:1', 'max:100'],
        ];
    }
}
