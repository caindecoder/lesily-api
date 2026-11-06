<?php

namespace App\Compliance\Breach\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Compliance\Breach\Entities\BreachIncidentCompany;

/**
 * @property-read BreachIncidentCompany $resource
 */
class BreachNoticeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $breach = $this->resource->breach;

        return [
            'breach_id' => $this->resource->breach_incident_id,
            'company_name' => $this->resource->company->name,
            'stage' => $breach->stage->value,
            'description' => $breach->description,
            'likely_consequences' => $breach->likely_consequences,
            'measures' => $breach->measures,
            'date_discovered' => $breach->date_discovered->toISOString(),
            'deadline' => $breach->deadline->toISOString(),
            'data_types' => $this->resource->data_types,
            'estimated_count' => $this->resource->estimated_count,
            'notified_at' => $this->resource->notified_at?->toISOString(),
            'filing_status' => $this->resource->filingStatus()->value,
            'filing_requested_at' => $this->resource->filing_requested_at?->toISOString(),
            'filing_requested_by' => $this->resource->filing_requested_by,
            'filed_at' => $this->resource->filed_at?->toISOString(),
        ];
    }
}
