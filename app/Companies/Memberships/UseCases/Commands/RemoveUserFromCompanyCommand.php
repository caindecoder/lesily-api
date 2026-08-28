<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Remove-member payload for the UserMembership aggregate (UC-1-024). Built at
 * the HTTP boundary via ::validateAndCreate (amendment AQ):
 * removedBy is server-sourced from the actor; the membership UUID rides the
 * route (M1 parity — no id field).
 */
class RemoveUserFromCompanyCommand extends Data
{
    public string $removedBy;

    /**
     * removedBy is server-sourced from the authenticated actor and carries no
     * rule (amendment AQ); the method stays because the AQ guard's clause 1
     * requires one on every controller-referenced command.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [];
    }
}
