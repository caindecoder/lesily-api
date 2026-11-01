<?php

namespace App\Companies\Memberships\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;

/**
 * Remove-member payload for the UserMembership aggregate (UC-1-024). Built at
 * the HTTP boundary via ::validateAndCreate (amendment AQ):
 * removedBy is server-sourced from the actor; the membership UUID rides the
 * route (M1 parity — no id field).
 */
class RemoveUserFromCompanyCommand extends Data
{
    #[WithCast(ModelCast::class, User::class)]
    public User $removedBy;

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
