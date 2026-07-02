<?php

namespace App\Companies\Memberships\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Companies\Memberships\Entities\Invitation;

/**
 * The created-invitation body (UC-1-018): {id, email, role, expires_at} per the
 * slice contract — the token never appears in any response (D3).
 *
 * @property-read Invitation $resource
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'email' => $this->resource->email,
            'role' => $this->resource->role?->code,
            'expires_at' => $this->resource->expires_at,
        ];
    }
}
