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
            'timeline' => $this->timeline(),
        ];
    }

    /**
     * One entry per lifecycle moment the request has reached. The listed order
     * is the order they happen in: a breach is flagged only while in progress,
     * and a download expires only after fulfilment.
     *
     * @return list<array{event: string, at: ?string}>
     */
    private function timeline(): array
    {
        $moments = [
            'requested' => $this->resource->requested_at,
            'sla_breached' => $this->resource->sla_breached_at,
            'fulfilled' => $this->resource->completed_at,
            'rejected' => $this->resource->rejected_at,
            'cancelled' => $this->resource->cancelled_at,
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
