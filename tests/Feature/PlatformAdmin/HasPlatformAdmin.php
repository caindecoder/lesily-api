<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;

trait HasPlatformAdmin
{
    protected function aPlatformAdmin(): User
    {
        $platformAdmin = User::factory()->create();
        $this->allowlist([$platformAdmin->getKey()]);

        return $platformAdmin;
    }

    protected function allowlist(array $ids): void
    {
        config(['platform.admin_user_ids' => $ids]);
    }
}
