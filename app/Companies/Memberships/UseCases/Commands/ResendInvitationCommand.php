<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Rotation payload for the UserMembership aggregate — an internal command built
 * via ::from() by the interactor, which mints the fresh plaintext token; the
 * aggregate hashes it and stamps the reset expiry, so the plaintext never
 * enters the event stream (D3; s110 R2). No id field: the aggregate is
 * retrieved BY the invitation UUID and stamps membershipId from $this->uuid().
 */
class ResendInvitationCommand extends Data
{
    public string $plaintextToken;

    public string $resentBy;
}
