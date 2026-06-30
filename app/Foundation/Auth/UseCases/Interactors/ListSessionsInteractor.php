<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Database\Eloquent\Collection;
use App\Foundation\Auth\UseCases\ActiveSessions;

/**
 * Lists a user's active login sessions (UC-1-010) — a read over the Sanctum
 * personal_access_tokens table, scoped to the user via the tokens() relationship
 * (own-only is inherent). No event sourcing: sessions are mutable credential state,
 * not domain state (slice §1). The "which sessions are active" policy is the shared
 * ActiveSessions definition (also used by the device-limit guard, so the list and the
 * cap can never disagree); the delivery-layer SessionResource formats them and flags
 * the current one.
 */
class ListSessionsInteractor
{
    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function execute(User $user): Collection
    {
        return ActiveSessions::constrain($user)->get();
    }
}
