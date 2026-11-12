<?php

namespace App\Companies\Memberships\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Foundation\UseCases\Attributes\OpaqueString;

/**
 * Remove-member payload for the UserMembership aggregate (UC-1-024). Built at
 * the HTTP boundary via ::validateAndCreate (amendment AQ); the company, the
 * route membership id and removedBy are server-sourced.
 */
class RemoveUserFromCompanyCommand extends Data
{
    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    #[OpaqueString('a membership id - its first read is the interactor\'s locked company-scoped lookup, so another company\'s id is a 404')]
    public string $membershipId;

    #[WithCast(ModelCast::class, User::class)]
    public User $removedBy;

    /**
     * Every key is server-sourced and carries no rule (amendment AQ); the
     * method stays because the AQ guard's clause 1
     * requires one on every controller-referenced command.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [];
    }
}
