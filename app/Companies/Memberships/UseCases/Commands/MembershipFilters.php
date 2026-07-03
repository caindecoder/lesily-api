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
     * The sortable fields (api-conventions §10.2 whitelist; `-` = descending).
     *
     * @var list<string>
     */
    private const array SORTABLE_FIELDS = ['name', '-name', 'created_at', '-created_at'];

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
        return [
            'role' => ['sometimes', 'string'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'invited'])],
            'search' => ['sometimes', 'string'],
            'sort' => ['sometimes', 'string', Rule::in(self::SORTABLE_FIELDS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
