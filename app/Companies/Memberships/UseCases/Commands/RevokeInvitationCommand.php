<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Revocation payload for the UserMembership aggregate (R-C s111). The reason is
 * the only client-supplied value — an optional {reason} body (R-A) bounded at
 * the boundary so an overlong string 422s instead of overflowing the column
 * in-transaction (amendment U — ::validateAndCreate, never ::from).
 */
class RevokeInvitationCommand extends Data
{
    public string $revokedBy;

    public ?string $reason;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        // No id field: the aggregate is retrieved BY the invitation UUID and
        // stamps membershipId from $this->uuid() — ResendInvitationCommand parity.
        return [
            'revokedBy' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
