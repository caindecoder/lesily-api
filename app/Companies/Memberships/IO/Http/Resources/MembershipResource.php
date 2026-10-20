<?php

namespace App\Companies\Memberships\IO\Http\Resources;

use stdClass;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One roster row (UC-1-017, slice §2): active memberships and live-pending
 * invitations share this shape (the Task-4 union — s110 R4a/R4b). Rows arrive
 * as the repository's aliased union columns. An active row carries the member's
 * identity with invited_at/last_active_at null until their writers land (R4b;
 * declared s108). An invited row carries the invitation UUID as id (D5b), a
 * null-identity user{} holding the invitation email, and the invitation's
 * issue time as invited_at.
 *
 * @property-read stdClass $resource
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'user' => [
                'id' => $this->resource->user_id,
                'name' => $this->resource->user_name,
                'email' => $this->resource->user_email,
            ],
            'role' => [
                'code' => $this->resource->role_code,
                'name' => $this->resource->role_name,
            ],
            'status' => $this->resource->status,
            'invited_at' => $this->parsedTimestamp($this->resource->invited_at),
            // Locked to the same serialization as invited_at now, so the shape
            // holds when its writer lands (declared s108).
            'last_active_at' => $this->parsedTimestamp($this->resource->last_active_at),
        ];
    }

    protected function parsedTimestamp(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
