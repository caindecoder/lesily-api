<?php

namespace App\Companies\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Companies\Entities\Company;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The owner-facing company details (UC-1-032, api-1-companies §GET). The B7
 * declared deferrals are enforced by shape: NO employee_count (chunk-2), NO
 * legal_hold_status (OQ-1-135), NO owner_id / subscription_summary — the
 * exact-contract test locks additions out.
 *
 * @property-read Company $resource
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'br_number' => $this->resource->br_number,
            'epf_number' => $this->resource->epf_number,
            'country' => $this->resource->country,
            'plan' => $this->resource->current_billing_plan?->value,
            'status' => $this->resource->status->value,
            // Explicit UTC ISO-8601 — decouples the wire contract from the
            // ambient app timezone (reviewer S2).
            'trial_ends_at' => $this->resource->trial_ends_at?->toISOString(),
            'settings' => new CompanySettingResource($this->resource->settings),
        ];
    }
}
