<?php

namespace App\Companies\Memberships\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Revocation payload for the UserMembership aggregate (R-C s111). The reason is
 * the only client-supplied value — an optional {reason} body (R-A) bounded at
 * the HTTP boundary so an overlong string 422s instead of overflowing the
 * column in-transaction (amendment AQ); revokedBy is server-sourced from the
 * actor and carries no rule.
 */
class RevokeInvitationCommand extends Data
{
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
