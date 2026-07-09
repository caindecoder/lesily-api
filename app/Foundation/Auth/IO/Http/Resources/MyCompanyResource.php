<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use stdClass;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One switcher-list row (UC-1-034, slice-companies B4) — the membership's
 * company as the repository's aliased columns: identity, the member's role,
 * plan/status (all statuses listed — the client renders suspended/expired
 * states, UC-1-034 3a/3b), the onboarding wizard flags and the join date.
 *
 * @property-read stdClass $resource
 */
class MyCompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'role' => [
                'code' => $this->resource->role_code,
                'name' => $this->resource->role_name,
            ],
            'plan' => $this->resource->plan,
            'status' => $this->resource->status,
            'is_default' => (bool) $this->resource->is_default,
            'onboarding' => [
                'completed' => (bool) $this->resource->onboarding_completed,
                'skipped' => (bool) $this->resource->onboarding_skipped,
            ],
            // Always present: company_user carries withTimestamps, so created_at
            // is stamped on every attach — no null branch to guard.
            'joined_at' => CarbonImmutable::parse((string) $this->resource->joined_at),
        ];
    }
}
