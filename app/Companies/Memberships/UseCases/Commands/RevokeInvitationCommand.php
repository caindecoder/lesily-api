<?php

namespace App\Companies\Memberships\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Revocation payload for the UserMembership aggregate (R-C s111). The reason is
 * the only client-supplied value — an optional {reason} body (R-A) bounded at
 * the HTTP boundary so an overlong string 422s instead of overflowing the
 * column in-transaction (amendment AQ); the company context, the route
 * invitation id and revokedBy (the actor) are server-sourced and carry no rule.
 */
class RevokeInvitationCommand extends Data
{
    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    #[OpaqueString('an invitation id - its first read is the interactor\'s company-scoped lookup, so another company\'s id is a 404')]
    public string $invitationId;

    #[WithCast(ModelCast::class, User::class)]
    public User $revokedBy;

    public ?string $reason;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        // No id field: the aggregate is retrieved BY the invitation UUID and
        // stamps membershipId from $this->uuid() — ResendInvitationCommand parity.
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
