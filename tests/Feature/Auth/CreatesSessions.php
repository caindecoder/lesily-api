<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;
use Illuminate\Support\Facades\Config;

trait CreatesSessions
{
    /**
     * Mint a production-faithful active session for $user: an explicit ~90-day expiry
     * exactly as SessionIssuer stamps it (createToken alone leaves expires_at null, which
     * no real login produces). Returns the NewAccessToken so callers get both the plain
     * text (to authenticate) and the token id (to target / assert on).
     */
    protected function aSessionFor(User $user, string $name = 'web'): NewAccessToken
    {
        return $user->createToken($name, ['*'], now()->addMinutes(Config::integer('sanctum.expiration')));
    }

    /**
     * Mint a session whose explicit expiry is already in the past — a dead session whose row
     * lingers until pruned.
     */
    protected function anExpiredSessionFor(User $user, string $name = 'old-laptop'): NewAccessToken
    {
        return $user->createToken($name, ['*'], now()->subDay());
    }
}
