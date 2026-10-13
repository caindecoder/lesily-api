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
            'download_available' => $this->resource->isDownloadWindowOpen(),
            'download_expires_at' => $this->resource->download_expires_at?->toISOString(),
            'cancelled_at' => $this->resource->cancelled_at?->toISOString(),
            'rejection_reason' => $this->resource->rejection_reason,
            'company' => ['id' => $this->resource->company->getKey(), 'name' => $this->resource->company->name],
            'days_remaining' => $this->resource->daysRemaining(),
            'sla_breached_at' => $this->resource->sla_breached_at?->toISOString(),
            'download_expired_at' => $this->resource->download_expired_at?->toISOString(),
            'timeline' => $this->timeline(),
        ];
    }

    /**
     * One entry per lifecycle moment the request has reached, oldest first.
     *
     * @return list<array{event: string, at: ?string}>
     */
    private function timeline(): array
    {
        $moments = [
            'requested' => $this->resource->requested_at,
            'fulfilled' => $this->resource->completed_at,
            'download_expired' => $this->resource->download_expired_at,
        ];

        $timeline = [];

        foreach ($moments as $event => $at) {
            if ($at !== null) {
                $timeline[] = ['event' => $event, 'at' => $at->toISOString()];
            }
        }

        return $timeline;
    }
}
