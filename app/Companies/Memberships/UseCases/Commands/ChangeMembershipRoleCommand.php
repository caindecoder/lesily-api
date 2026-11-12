<?php

namespace App\Companies\Memberships\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use App\Companies\Entities\Company;
use Illuminate\Validation\Rules\In;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Role-change payload for the UserMembership aggregate (R-C/R-D s112). Built at
 * the HTTP boundary via ::validateAndCreate (amendment AQ): roleCode from the
 * request body; the company, the route membership id and changedBy are
 * server-sourced and carry no rule.
 */
class ChangeMembershipRoleCommand extends Data
{
    /**
     * The seeded roles a change may assign — owner is never change-role-assignable
     * (ownership transfer only, R-A s112), platform_super_admin is not a company
     * role, and manager is seeded is_assignable=false (InviteUserCommand parity;
     * the Enterprise custom-role gate is a chunk-2 declared deferral, R-F s112).
     *
     * @var list<string>
     */
    private const array ASSIGNABLE_ROLE_CODES = ['admin', 'hr_officer', 'payroll_officer', 'employee', 'auditor'];

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    #[OpaqueString('a membership id - its first read is the interactor\'s locked company-scoped lookup, so another company\'s id is a 404')]
    public string $membershipId;

    #[OpaqueString('a role code - the seeded catalog, open to custom roles later')]
    public string $roleCode;

    #[WithCast(ModelCast::class, User::class)]
    public User $changedBy;

    /**
     * @return array<string, array<int, string|In>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'roleCode' => ['required', 'string', Rule::in(self::ASSIGNABLE_ROLE_CODES)],
        ];
    }
}
