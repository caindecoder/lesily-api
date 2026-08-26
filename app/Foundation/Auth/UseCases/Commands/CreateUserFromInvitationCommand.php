<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Genesis payload for the invite-accept path on the User aggregate (internal
 * command, built via ::from() — its inputs are already validated at the HTTP
 * boundary by AcceptInvitationCommand or minted by the interactor; aggregate-
 * takes-Command per the s071 standing convention). The interactor hashes the
 * chosen password before constructing this, so plaintext never crosses the
 * aggregate boundary.
 */
class CreateUserFromInvitationCommand extends Data
{
    public string $companyId;

    public string $email;

    public string $passwordHash;

    public string $name;
}
