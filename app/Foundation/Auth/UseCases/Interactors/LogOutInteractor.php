<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;

/**
 * The "Log Out" use case (UC-1-004): revokes the current session by deleting the request's
 * Sanctum access token. Token revocation is application work, not a delivery concern — it
 * was relocated out of LogoutController (session 090 token-lifecycle refactor, E07 Dependency
 * Rule / SRP). Current-token-only: the user's other sessions survive (logout-all is UC-1-011,
 * deferred to the sessions slice).
 */
class LogOutInteractor
{
    public function execute(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
