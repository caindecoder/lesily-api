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
            // False until UC-1-139 lands users.default_company_id (T1 pin,
            // slice-companies B4) — then derived against the user's default.
            'is_default' => false,
            'onboarding' => [
                'completed' => (bool) $this->resource->onboarding_completed,
                'skipped' => (bool) $this->resource->onboarding_skipped,
            ],
            'joined_at' => $this->parsedTimestamp($this->resource->joined_at),
        ];
    }

    private function parsedTimestamp(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value);
    }
}
