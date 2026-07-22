<?php

namespace App\Companies\OwnershipTransfers\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;

/**
 * The transfer-history query input (UC-1-126): whitelisted state/sort/pagination
 * params per api-conventions §9 + §10. Shape-only validation; unknown query keys
 * are refused (the per-endpoint whitelist — §10.1) because Data would silently
 * ignore them. Mirrors MembershipFilters (the UC-1-017 roster precedent).
 */
class OwnershipTransferFilters extends Data
{
    /**
     * The query keys this endpoint accepts (api-conventions §10.1 whitelist).
     *
     * @var list<string>
     */
    private const array QUERY_KEY_WHITELIST = ['state', 'sort', 'page', 'per_page'];

    /**
     * The sort tokens this endpoint accepts — the newest-first default plus its
     * ascending inverse (api-1-ownership-transfer §2 filter whitelist).
     *
     * @var list<string>
     */
    private const array SORTABLE_FIELDS = ['-requested_at', 'requested_at'];

    public ?string $state = null;

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
            'state' => ['sometimes', Rule::enum(OwnershipTransferStatus::class)],
            'sort' => ['sometimes', 'string', Rule::in(self::SORTABLE_FIELDS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
