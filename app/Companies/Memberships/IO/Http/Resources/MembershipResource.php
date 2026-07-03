<?php

namespace App\Companies\Memberships\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Companies\Entities\CompanyUser;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One roster row (UC-1-017, slice §2): the membership id, the member's user and
 * role, and the derived status trio. status is always 'active' this increment —
 * removed members are excluded and invited people have no pivot row (the
 * status=invited union lands at Task 4). invited_at/last_active_at emit null
 * until their source columns/writers land (Task 3+; declared s108).
 *
 * @mixin CompanyUser
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'role' => [
                'code' => $this->role->code,
                'name' => $this->role->name,
            ],
            'status' => 'active',
            'invited_at' => null,
            'last_active_at' => null,
        ];
    }
}
