<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Role-change payload for the UserMembership aggregate (R-C/R-D s112). Built at
 * the HTTP boundary via ::validateAndCreate (amendment AQ):
 * roleCode from the request body, changedBy server-sourced from the actor.
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

    public string $roleCode;

    public string $changedBy;

    /**
     * @return array<string, array<int, string|In>>
     */
    public static function rules(ValidationContext $context): array
    {
        // No id field: the aggregate is retrieved BY the membership UUID from
        // the route (M1 parity — RevokeInvitationCommand precedent). changedBy is
        // server-sourced from the actor and carries no rule (amendment AQ).
        return [
            'roleCode' => ['required', 'string', Rule::in(self::ASSIGNABLE_ROLE_CODES)],
        ];
    }
}
