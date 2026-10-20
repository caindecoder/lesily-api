<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Foundation\RolesPermissions\Services\PlatformAdminAllowlist;

/**
 * The /api/v1/admin/* family's gate (alias platform.admin) — runs directly
 * after auth:sanctum, deliberately with NO auth.company (the family is
 * cross-tenant by design; cd-foundation-http §3). Any caller off the
 * config/platform.php allowlist gets the generic 404 — never a 403 — so the
 * admin surface never acknowledges its existence (UC-1-122 2a no-info-leak).
 *
 * INTERIM binding (slice-plans-billing B2): an env allowlist of user ids, to
 * be replaced by the platform-admin slice's real admin auth. Extending it to a
 * further admin surface needs that surface's slice design — the invoice review
 * (UC-1-122) and the ownership-transfer case family (slice-11R spec §4 seam
 * reuse) are the two designed consumers.
 */
class EnsurePlatformAdmin
{
    public function __construct(private readonly PlatformAdminAllowlist $platformAdmins) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isAllowlisted($request)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }

    /**
     * Strict membership over the normalized allowlist, so blank segments fail
     * closed.
     */
    protected function isAllowlisted(Request $request): bool
    {
        return in_array($request->user()?->getKey() ?? '', $this->platformAdmins->userIds(), true);
    }
}
