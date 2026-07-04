<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Rotation payload for the UserMembership aggregate (SP-style internal command —
 * CreateInvitationCommand parallel): the interactor mints the fresh plaintext
 * token; the aggregate hashes it and stamps the reset expiry, so the plaintext
 * never enters the event stream (D3; s110 R2).
 */
class ResendInvitationCommand extends Data
{
    public string $invitationId;

    public string $plaintextToken;

    public string $resentBy;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'invitationId' => ['required', 'uuid'],
            'plaintextToken' => ['required', 'string', 'min:40', 'max:255'],
            'resentBy' => ['required', 'uuid'],
        ];
    }
}
