<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use App\Companies\Memberships\Exceptions\InvalidInvitationException;

/**
 * Accept a team invitation via its emailed single-use token (UC-1-019).
 * Single-action by design — the declared exception to the multi-action
 * resource-controller convention (slice §4, session 106): the accept is a
 * guest-or-authenticated capability URL, not a company-context resource action.
 */
class AcceptInvitationController
{
    public function __invoke(string $token): never
    {
        throw new InvalidInvitationException;
    }
}
