<?php

namespace App\Companies\Memberships\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Foundation\RolesPermissions\Entities\Permission;

/**
 * @mixin Permission
 */
class PermissionResource extends JsonResource
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
            'module' => $this->module,
        ];
    }
}
