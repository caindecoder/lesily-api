<?php

namespace Tests\Unit\Conventions\Fixtures\ModelKeyReads;

use App\Foundation\RolesPermissions\Entities\Role;

final class NullsafeModelRead
{
    public function ownerRoleKey(): ?string
    {
        return Role::findByCode('owner')?->id;
    }
}
