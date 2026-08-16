<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The roster query input (UC-1-017): whitelisted filter/sort/pagination params per
 * api-conventions §9 + §10. Shape-only validation; unknown query keys are refused
 * (the per-endpoint whitelist rule — §10.1) because Data would silently ignore them.
 */
class MembershipFilters extends Data
{
    /**
     * The query keys this endpoint accepts (api-conventions §10.1 whitelist).
     *
     * @var list<string>
     */
    private const array QUERY_KEY_WHITELIST = ['role', 'status', 'search', 'sort', 'page', 'per_page'];

    /**
     * The sort tokens this endpoint accepts — exactly api-1-users' declared pair
     * (`?sort=-created_at|name`; api-conventions §10.2 per-endpoint whitelist).
     *
     * @var list<string>
     */
    private const array SORTABLE_FIELDS = ['name', '-created_at'];

    public ?string $role = null;

    public ?string $status = null;

    public ?string $search = null;

    public ?string $sort = null;

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
        // filled: an empty string skips every non-implicit rule (OQ-1-163) —
        // only an implicit rule can refuse '' at this seam. Listed first so
        // its removal stays test-visible and the empty-value message leads.
        return [
            'role' => ['filled', 'sometimes', 'string'],
            'status' => ['filled', 'sometimes', 'string', Rule::in(['active', 'invited'])],
            'search' => ['filled', 'sometimes', 'string'],
            'sort' => ['filled', 'sometimes', 'string', Rule::in(self::SORTABLE_FIELDS)],
            'page' => ['filled', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['filled', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
