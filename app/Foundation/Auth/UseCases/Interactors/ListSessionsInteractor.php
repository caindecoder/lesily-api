<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lists a user's active login sessions (UC-1-010) — a read over the Sanctum
 * personal_access_tokens table, scoped to the user via the tokens() relationship
 * (own-only is inherent). No event sourcing: sessions are mutable credential state,
 * not domain state (slice §1). A thin interactor owns the "which sessions" policy;
 * the delivery-layer SessionResource formats them and flags the current one.
 */
class ListSessionsInteractor
{
    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function execute(User $user): Collection
    {
        // Active = exactly what Sanctum would authenticate (Guard::isValidAccessToken):
        // a non-past expiry (null OR future) AND a created_at still inside the configured
        // global window. Mirroring both clauses keeps the list from ever showing a session
        // that would in fact fail to authenticate. The expiry OR is GROUPED so it stays
        // inside the relationship's tokenable scope — an ungrouped OR would leak other
        // users' active tokens. The window always applies: sanctum.expiration is always
        // configured here (SessionIssuer mints against it).
        $windowStart = now()->subMinutes(Config::integer('sanctum.expiration'));

        return $user->tokens()
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where('created_at', '>', $windowStart)
            ->get();
    }
}
