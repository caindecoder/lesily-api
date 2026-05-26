<?php

namespace App\Foundation\Http\Middleware;

use Closure;
use App\Models\User;
use App\Models\Company;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;

class CheckPermissionMiddleware
{
    public function __construct(private readonly PermissionEvaluator $evaluator) {}

    public function handle(Request $request, Closure $next, string $permissionCode): Response
    {
        $user = $request->user();
        $company = $this->resolveCompany($request);

        if ($user === null || $company === null) {
            throw new RoleInsufficientException(permission: $permissionCode);
        }

        if (! $this->evaluator->canUserDo($user, $company, $permissionCode)) {
            throw new RoleInsufficientException(
                permission: $permissionCode,
                userRoleCode: $this->resolveUserRoleCode($user, $company),
            );
        }

        return $next($request);
    }

    private function resolveCompany(Request $request): ?Company
    {
        $companyId = $request->header('X-Company-Id');
        if (! is_string($companyId) || $companyId === '') {
            return null;
        }

        return Company::query()->find($companyId);
    }

    private function resolveUserRoleCode(User $user, Company $company): ?string
    {
        $roleId = $this->evaluator->resolveRoleId($user, $company);
        if ($roleId === null) {
            return null;
        }
        $role = Role::query()->find($roleId);

        return $role?->code;
    }
}
