<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The /api/v1/admin/* family's gate (alias platform.admin) — runs directly
 * after auth:sanctum, deliberately with NO auth.company (the family is
 * cross-tenant by design; cd-foundation-http §3). Any caller off the
 * config/platform.php allowlist gets the generic 404 — never a 403 — so the
 * admin surface never acknowledges its existence (UC-1-122 2a no-info-leak).
 *
 * INTERIM binding (slice-plans-billing B2): an env allowlist of user ids, to
 * be replaced by the platform-admin slice's real admin auth. Never extend it
 * to further admin surfaces without that slice's design (spec §9 pointer).
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isAllowlisted($request)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }

    /**
     * Strict membership over the normalized allowlist. config/platform.php
     * hands over the raw env explode; normalization lives here at the
     * use-site (LD trim/filter idiom) so padded entries match and blank
     * segments — including the absent-env [''] shape — fail closed.
     */
    private function isAllowlisted(Request $request): bool
    {
        return collect((array) config('platform.admin_user_ids', []))
            ->map(fn ($id): string => trim((string) $id))
            ->reject(fn (string $id): bool => $id === '')
            ->containsStrict((string) $request->user()?->getKey());
    }
}
