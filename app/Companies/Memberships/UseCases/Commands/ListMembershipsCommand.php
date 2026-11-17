<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use App\Foundation\UseCases\Commands\QueryCommand;
use App\Foundation\UseCases\Attributes\OpaqueString;
use App\Companies\Memberships\Enums\MembershipStatus;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The roster query input (UC-1-017): whitelisted filter/sort/pagination params per
 * api-conventions §9 + §10. Shape-only validation; unknown query keys are refused
 * (the per-endpoint whitelist rule — §10.1) because Data would silently ignore them.
 */
class ListMembershipsCommand extends QueryCommand
{
    /**
     * The query keys this endpoint accepts (api-conventions §10.1 whitelist).
     *
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['role', 'status', 'search', 'sort', 'page', 'per_page'];

    /**
     * The sort tokens this endpoint accepts — exactly api-1-users' declared pair
     * (`?sort=-created_at|name`; api-conventions §10.2 per-endpoint whitelist).
     *
     * @var list<string>
     */
    private const array SORTABLE_FIELDS = ['name', '-created_at'];

    #[OpaqueString('a role code - the seeded catalog, open to custom roles later')]
    public ?string $role = null;

    public ?MembershipStatus $status = null;

    public ?string $search = null;

    #[OpaqueString('a per-endpoint sort token the repository maps to columns')]
    public ?string $sort = null;

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
            'role' => ['filled', 'sometimes', 'string'],
            'status' => ['filled', 'sometimes', 'string', Rule::enum(MembershipStatus::class)],
            'search' => ['filled', 'sometimes', 'string'],
            'sort' => ['filled', 'sometimes', 'string', Rule::in(self::SORTABLE_FIELDS)],
        ];
    }
}
