<?php

namespace Tests\Unit\Conventions\Fixtures\ModelKeyReads;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Foundation\RolesPermissions\Entities\Role;

/**
 * @mixin User
 */
final class GetKeyRead extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'owner_role_id' => Role::findByCode('owner')?->getKey(),
        ];
    }

    public function keyOf(User $user): mixed
    {
        return $user->getKey();
    }
}
