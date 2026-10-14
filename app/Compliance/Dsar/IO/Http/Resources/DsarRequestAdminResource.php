<?php

namespace App\Compliance\Dsar\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

/**
 * @property DsarRequestRecord $resource
 */
class DsarRequestAdminResource extends JsonResource
{
    /**
     * The member view of the request, plus what a platform admin needs.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new DsarRequestResource($this->resource))->toArray($request),
            'company' => ['id' => $this->resource->company->getKey(), 'name' => $this->resource->company->name],
            'days_remaining' => $this->resource->daysRemaining(),
            'sla_breached_at' => $this->resource->sla_breached_at?->toISOString(),
            'download_expired_at' => $this->resource->download_expired_at?->toISOString(),
        ];
    }
}
