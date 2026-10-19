<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use App\Foundation\Auth\UseCases\TokenRevoker;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Auth\UseCases\PasswordVerifier;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;
use App\Foundation\Auth\UseCases\Commands\LogoutAllCommand;

/**
 * Log out all other devices (UC-1-011): re-authenticate the user with their current password,
 * then revoke every one of their Sanctum tokens except the current session. The password gate
 * is the use case's policy (E07) — it MUST reject before any revoke so a wrong password leaves
 * every session intact (the Minimal Guarantee). Token-table side-effect only — no domain event;
 * the revoke itself is delegated to the shared TokenRevoker. The current token id is resolved by
 * the delivery layer (the request's currentAccessToken) and passed in, keeping this interactor
 * free of request-scoped Sanctum state.
 */
class RevokeAllSessionsInteractor
{
    public function __construct(
        private TokenRevoker $tokenRevoker,
        private PasswordVerifier $passwordVerifier,
        private AccessLogRecorder $accessLog,
    ) {}

    public function execute(User $user, LogoutAllCommand $command, string $currentTokenId): int
    {
        $this->passwordVerifier->verify($user, $command->password);
        $revoked = $this->tokenRevoker->revokeAllForUserExcept($user, $currentTokenId);
        $this->accessLog->note(AccessEvent::OtherSessionsRevoked, null, $user);

        return $revoked;
    }
}
