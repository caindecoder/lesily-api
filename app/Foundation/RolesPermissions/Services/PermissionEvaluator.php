<?php

namespace App\Foundation\RolesPermissions\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Foundation\RolesPermissions\Entities\Role;

/**
 * The role gate's evaluator. Resolves a (user, company) pair to its role via the
 * company_user pivot, then checks whether that role grants the permission code.
 * Mirrors the LeaveDates (user, company) -> role lookup (request()->employment),
 * generalised to Lesily's roles/permissions catalog. Caching deferred per
 * cd-roles-permissions §7 OQ #4.
 */
class PermissionEvaluator
{
    public function canUserDo(User $user, Company $company, string $permissionCode): bool
    {
        $roleId = DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('deleted_at')
            ->value('role_id');

        if (! is_string($roleId)) {
            return false;
        }

        return $this->canRoleDo($roleId, $permissionCode);
    }

    private function canRoleDo(string $roleId, string $permissionCode): bool
    {
        return Role::query()
            ->whereKey($roleId)
            ->whereHas('permissions', fn ($query) => $query->where('code', $permissionCode))
            ->exists();
    }
}
