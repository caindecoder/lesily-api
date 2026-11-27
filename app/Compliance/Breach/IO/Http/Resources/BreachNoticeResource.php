<?php

namespace App\Compliance\Breach\IO\Http\Resources;

use Carbon\CarbonImmutable;
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
        $breachIncident = $this->resource->breach;
        /** @var CarbonImmutable $notifiedAt the list holds notified rows only (whereNotified) */
        $notifiedAt = $this->resource->notified_at;

        return [
            'breach_id' => $this->resource->breach_incident_id,
            'company_name' => $this->resource->company->name,
            'stage' => $breachIncident->stage->value,
            'description' => $breachIncident->description,
            'likely_consequences' => $breachIncident->likely_consequences,
            'measures' => $breachIncident->measures,
            'date_discovered' => $breachIncident->date_discovered->toISOString(),
            'deadline' => $breachIncident->deadline->toISOString(),
            'data_types' => $this->resource->data_types,
            'estimated_count' => $this->resource->estimated_count,
            'notified_at' => $notifiedAt->toISOString(),
            'filing_status' => $this->resource->filingStatus()->value,
            'filing_requested_at' => $this->resource->filing_requested_at?->toISOString(),
            'filing_requested_by' => $this->resource->filing_requested_by,
            'filed_at' => $this->resource->filed_at?->toISOString(),
        ];
    }
}
