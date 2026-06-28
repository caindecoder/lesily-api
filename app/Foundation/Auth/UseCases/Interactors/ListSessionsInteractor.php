<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
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
        // Active-only: a never-expiring token (null) or one whose expiry is still in
        // the future. The closure GROUPS the OR so it stays inside the relationship's
        // tokenable scope — an ungrouped OR would leak other users' active tokens.
        /** @var Collection<int, PersonalAccessToken> $tokens */
        $tokens = $user->tokens()
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        return $tokens;
    }
}
