<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Genesis payload for the UserMembership aggregate — an internal command built
 * via ::from() by the interactor, which resolves the role and mints the
 * plaintext token; the aggregate hashes it and stamps the expiry, so the
 * plaintext never enters the event stream (D3). The client-supplied fields are
 * validated once, at the HTTP boundary (InviteUserCommand).
 */
class CreateInvitationCommand extends Data
{
    public string $companyId;

    public string $email;

    public string $roleId;

    public string $invitedByUserId;

    public string $plaintextToken;
}
