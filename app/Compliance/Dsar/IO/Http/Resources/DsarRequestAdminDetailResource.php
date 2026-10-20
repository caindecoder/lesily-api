<?php

namespace App\Compliance\Dsar\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

/**
 * @property DsarRequestRecord $resource
 */
class DsarRequestAdminDetailResource extends JsonResource
{
    /**
     * The admin row of the request, plus its timeline.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new DsarRequestAdminResource($this->resource))->toArray($request),
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
    protected function timeline(): array
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
