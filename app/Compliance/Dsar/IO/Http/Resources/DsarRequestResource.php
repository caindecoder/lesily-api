<?php

namespace App\Compliance\Dsar\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

/**
 * @property DsarRequestRecord $resource
 */
class DsarRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'status' => $this->resource->status->value,
            'request_type' => $this->resource->request_type,
            'subject' => ['id' => $this->resource->subject->getKey(), 'name' => $this->resource->subject->name],
            'requested_by' => ['id' => $this->resource->requestedBy->getKey(), 'name' => $this->resource->requestedBy->name],
            'requested_at' => $this->resource->requested_at->toISOString(),
            'sla_deadline' => $this->resource->sla_deadline->toISOString(),
            'completed_at' => $this->resource->completed_at?->toISOString(),
            'download_available' => $this->resource->isDownloadable(),
            'download_expires_at' => $this->resource->download_expires_at?->toISOString(),
            'cancelled_at' => $this->resource->cancelled_at?->toISOString(),
            'rejection_reason' => $this->resource->rejection_reason,
        ];
    }
}
