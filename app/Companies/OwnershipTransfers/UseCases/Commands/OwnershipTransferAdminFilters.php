<?php

namespace App\Companies\OwnershipTransfers\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;

/**
 * The platform-admin queue query input (UC-1-127): whitelisted state /
 * company / pagination params per api-conventions §9 + §10. Shape-only
 * validation; unknown query keys are refused (the per-endpoint whitelist —
 * §10.1) because Data would silently ignore them.
 *
 * Deliberately carries NO `sort` key, unlike the tenant-facing
 * OwnershipTransferFilters: the queue's ordering is hard-coded
 * soonest-deadline-first (F1, s148), matching LeaveDates' operator queues
 * (ListTrialCompaniesAction — `->orderBy('trial_ends_at')`, no client sort
 * anywhere in that codebase). A `?sort=` therefore 422s as an unknown key.
 *
 * A sibling of OwnershipTransferFilters rather than a subclass: the two serve
 * different actors and will change for different reasons (E49 accidental
 * duplication; E66 §4 independence through irrelevance).
 */
class OwnershipTransferAdminFilters extends Data
{
    /**
     * The query keys this endpoint accepts (api-conventions §10.1 whitelist).
     *
     * @var list<string>
     */
    private const array QUERY_KEY_WHITELIST = ['state', 'company_id', 'page', 'per_page'];

    public ?string $state = null;

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
            'state' => ['sometimes', Rule::enum(OwnershipTransferStatus::class)],
            'company_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
