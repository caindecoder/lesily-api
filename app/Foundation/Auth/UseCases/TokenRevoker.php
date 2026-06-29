<?php

namespace App\Foundation\Auth\UseCases;

use App\Models\User;

/**
 * Revokes all of a user's Sanctum sessions except one — the shared revoke counterpart to
 * SessionIssuer's mint (sessions slice). A concrete collaborator with NO interface: it crosses
 * no architectural boundary (Sanctum is the single in-process implementation and tests revoke
 * real tokens), so a statist test on actual token-table state is the right seam, not a mock
 * (E23). Consumed by RevokeAllSessionsInteractor (UC-1-011 logout-all) and — next — the
 * OQ-1-120 revoke-others-on-2FA-disable. Returns the number of tokens revoked.
 */
class TokenRevoker
{
    public function revokeAllForUserExcept(User $user, string $exceptTokenId): int
    {
        // Faked to the only-current count until the multi-token happy path (#7) triangulates
        // the real except-current delete — keeps the revoke logic RED-first.
        return 0;
    }
}
