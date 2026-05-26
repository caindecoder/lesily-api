<?php

namespace App\Foundation\RolesPermissions\Services;

use App\Models\User;
use App\Models\Company;
use App\Foundation\RolesPermissions\Entities\Role;

class PermissionEvaluator
{
    public function canUserDo(User $user, Company $company, string $permissionCode): bool
    {
        $roleId = $this->resolveRoleId($user, $company);
        if ($roleId === null) {
            return false;
        }

        return $this->canRoleDo($roleId, $permissionCode);
    }

    public function canRoleDo(string $roleId, string $permissionCode): bool
    {
        $role = Role::query()->with('permissions')->find($roleId);
        if ($role === null) {
            return false;
        }

        return $role->permissions->pluck('code')->contains($permissionCode);
    }

    public function resolveRoleId(User $user, Company $company): ?string
    {
        $membership = $company->users()
            ->where('users.id', $user->id)
            ->first();

        if ($membership === null) {
            return null;
        }

        $roleId = $membership->pivot->role_id ?? null;

        return is_string($roleId) ? $roleId : null;
    }
}
