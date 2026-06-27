<?php

namespace App\Foundation\Auth\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use App\Foundation\Auth\UseCases\Results\IssuedSession;

/**
 * Mints a Sanctum session token for an authenticated user (E07: token issuance is application
 * work, not a delivery concern; relocated out of the controllers, session 090). Login, 2FA-verify,
 * and register all route their mint through here, making it the single shared mint site. A concrete
 * collaborator with no interface — Sanctum is the only implementation and tests mint real
 * tokens, so there is no substitution/mock boundary (cf. RegistrationUniquenessGuard).
 * The token is named for the device ($deviceName) so the Active Sessions list can identify it
 * (sessions slice). Returns an IssuedSession so the Sanctum NewAccessToken never leaks past the
 * use case.
 */
class SessionIssuer
{
    public function issue(User $user, string $deviceName): IssuedSession
    {
        // Stamp the 90-day expiry explicitly so the column (and the returned expiresAt) is
        // authoritative — createToken leaves it null otherwise, and config('sanctum.expiration')
        // only governs the auth-time check.
        $token = $user->createToken($deviceName, expiresAt: now()->addMinutes(Config::integer('sanctum.expiration')));

        return new IssuedSession($token->plainTextToken, $token->accessToken->expires_at);
    }
}
