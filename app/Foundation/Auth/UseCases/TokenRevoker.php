<?php

namespace App\Foundation\Auth\UseCases;

use App\Models\User;

/**
 * Revokes a user's Sanctum sessions — the shared revoke counterpart to SessionIssuer's mint. Pass a
 * token id to keep that one session alive (change-password / logout-all / 2FA-disable revoke every
 * OTHER session); pass null to revoke ALL of them (reset-password — the guest re-logs in with the new
 * password). A concrete collaborator with NO interface: it crosses no architectural boundary (Sanctum
 * is the single in-process implementation and tests revoke real tokens), so a statist test on actual
 * token-table state is the right seam, not a mock (E23). Returns the number of tokens revoked.
 */
class TokenRevoker
{
    public function revokeAllForUserExcept(User $user, ?string $exceptTokenId = null): int
    {
        // whereKeyNot(null) is NOT a no-op: the query builder rewrites `id != null` into
        // `id IS NOT NULL` (Query\Builder::where's null short-cut), which matches every row — so a
        // null $exceptTokenId revokes ALL sessions, while a string keeps that one. Do not "simplify".
        return $user->tokens()->whereKeyNot($exceptTokenId)->delete();
    }
}
