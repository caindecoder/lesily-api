<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Role-change payload for the UserMembership aggregate (R-C/R-D s112). Built at
 * the controller boundary via ::validateAndCreate — never ::from (amendment U):
 * roleCode from the request body, changedBy server-sourced from the actor.
 */
class ChangeMembershipRoleCommand extends Data
{
    public string $changedBy;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        // No id field: the aggregate is retrieved BY the membership UUID from
        // the route (M1 parity — RevokeInvitationCommand precedent).
        return [
            'changedBy' => ['required', 'uuid'],
        ];
    }
}
