<?php

namespace App\Compliance\Breach\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Compliance\Breach\Entities\BreachIncidentRecord;

/**
 * @property-read BreachIncidentRecord $resource
 */
class BreachIncidentAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $total = $this->resource->companies_sum_estimated_count;

        return [
            'id' => $this->resource->getKey(),
            'stage' => $this->resource->stage->value,
            'severity' => $this->resource->severity->value,
            'description' => $this->resource->description,
            'date_discovered' => $this->resource->date_discovered->toISOString(),
            'deadline' => $this->resource->deadline->toISOString(),
            'deadline_missed_at' => $this->resource->deadline_missed_at?->toISOString(),
            'companies_notified_at' => $this->resource->companies_notified_at?->toISOString(),
            'resolved_at' => $this->resource->resolved_at?->toISOString(),
            'false_alarm_at' => $this->resource->false_alarm_at?->toISOString(),
            'companies_count' => $this->resource->companies_count,
            'total_estimated_count' => $total === null ? null : (int) $total,
        ];
    }
}
