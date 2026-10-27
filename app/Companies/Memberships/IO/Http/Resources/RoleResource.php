<?php

namespace App\Companies\Memberships\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Foundation\RolesPermissions\Entities\Role;

/**
 * @mixin Role
 *
 * @property-read int $members_count
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'scope' => $this->scope,
            'is_assignable' => $this->is_assignable,
            'is_system' => $this->is_system,
            'sort_order' => $this->sort_order,
            'permissions' => PermissionResource::collection($this->permissions),
            'permissions_count' => $this->permissions_count,
            'members_count' => $this->members_count,
        ];
    }
}
