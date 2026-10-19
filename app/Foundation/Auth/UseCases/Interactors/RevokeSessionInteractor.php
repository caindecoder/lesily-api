<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;

/**
 * Revoke a single session: hard-delete one of the user's own Sanctum personal access
 * tokens by id. The $user->tokens() scoping is the isolation backstop — a token that is
 * unknown or owned by another user matches nothing and raises ModelNotFoundException,
 * which the api renders as 404 RESOURCE_NOT_FOUND (existence hidden). Token-table
 * side-effect only — no domain event (sessions slice; /simplicity-check 2026-06-27 ruled
 * this inline, no TokenRevoker).
 */
class RevokeSessionInteractor
{
    public function __construct(private readonly AccessLogRecorder $accessLog) {}

    public function execute(User $user, string $tokenId): void
    {
        $user->tokens()->whereKey($tokenId)->firstOrFail()->delete();
        $this->accessLog->note(AccessEvent::SessionRevoked, null, $user);
    }
}
