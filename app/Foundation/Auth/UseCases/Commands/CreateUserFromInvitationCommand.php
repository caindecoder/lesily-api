<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Genesis payload for the invite-accept path on the User aggregate (SP-style
 * internal command — CreateInvitationCommand parallel; aggregate-takes-Command
 * per the s071 standing convention). The interactor hashes the chosen password
 * before constructing this, so plaintext never crosses the aggregate boundary.
 */
class CreateUserFromInvitationCommand extends Data
{
    public string $companyId;

    public string $email;

    public string $passwordHash;

    public string $name;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        // max:255 bounds the varchar-backed fields at the command boundary — an
        // over-long value would record a genesis event whose projection can
        // never succeed (poison event on replay; CreateInvitationCommand precedent).
        return [
            'companyId' => ['required', 'uuid'],
            'email' => ['required', 'email', 'max:255'],
            'passwordHash' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
