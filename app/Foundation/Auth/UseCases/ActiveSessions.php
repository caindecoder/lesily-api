<?php

namespace App\Foundation\Auth\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The single definition of an "active" Sanctum session: exactly what
 * Guard::isValidAccessToken would authenticate — a non-past expiry (null OR future)
 * AND a created_at still inside the configured global window. Shared so the session
 * LIST (UC-1-010, ListSessionsInteractor) and the device-limit COUNT (UC-1-002
 * alt-5a, DeviceLimitGuard) can never disagree about which sessions are active. The
 * expiry OR is grouped so it stays inside the caller's tokenable scope — an ungrouped
 * OR would leak other users' active tokens. The window always applies: sanctum.expiration
 * is always configured (SessionIssuer mints against it).
 */
class ActiveSessions
{
    /**
     * @return MorphMany<PersonalAccessToken, User>
     */
    public static function constrain(User $user): MorphMany
    {
        $windowStart = now()->subMinutes(Config::integer('sanctum.expiration'));

        return $user->tokens()
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where('created_at', '>', $windowStart);
    }
}
