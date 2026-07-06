<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Remove-member payload for the UserMembership aggregate (UC-1-024). Built at
 * the controller boundary via ::validateAndCreate — never ::from (amendment U):
 * removedBy is server-sourced from the actor; the membership UUID rides the
 * route (M1 parity — no id field).
 */
class RemoveUserFromCompanyCommand extends Data
{
    public string $removedBy;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'removedBy' => ['required', 'uuid'],
        ];
    }
}
