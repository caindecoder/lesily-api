<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;

/**
 * The role gate (second of the two gates per api-conventions §11). Denies the
 * request when the member's role for the current company does not grant the
 * route's permission. Runs behind auth:sanctum + AuthorizeCompany, which
 * guarantee the user and the validated company context ($request->company()).
 */
class EnsurePermission
{
    public function __construct(private readonly PermissionEvaluator $evaluator) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var User $user — guaranteed by upstream auth:sanctum (cd-foundation-http step 3) */
        $user = $request->user();
        $company = $request->company();

        if (! $this->evaluator->canUserDo($user, $company, $permission)) {
            throw new RoleInsufficientException($permission);
        }

        return $next($request);
    }
}
