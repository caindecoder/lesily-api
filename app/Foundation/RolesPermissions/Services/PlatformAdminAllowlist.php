<?php

namespace App\Foundation\RolesPermissions\Services;

/**
 * The platform admins named by config/platform.php — the raw env explode,
 * normalized here (the LD trim/filter idiom): padded entries match, and blank
 * segments, including the absent-env [''] shape, name no one.
 */
class PlatformAdminAllowlist
{
    /**
     * @return array<int, string>
     */
    public function userIds(): array
    {
        /** @var array<int, string> $adminUserIds raw env explode (config/platform.php) */
        $adminUserIds = config('platform.admin_user_ids', []);

        return collect($adminUserIds)
            ->map(fn (string $id): string => trim($id))
            ->reject(fn (string $id): bool => $id === '')
            ->all();
    }
}
