<?php

namespace App\Compliance\Breach\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;

/**
 * @property-read BreachIncidentRecord $resource
 */
class BreachIncidentAdminDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'stage' => $this->resource->stage->value,
            'severity' => $this->resource->severity->value,
            'description' => $this->resource->description,
            'likely_consequences' => $this->resource->likely_consequences,
            'measures' => $this->resource->measures,
            'date_discovered' => $this->resource->date_discovered->toISOString(),
            'deadline' => $this->resource->deadline->toISOString(),
            'recorded_at' => $this->resource->recorded_at->toISOString(),
            'recorded_by' => $this->resource->recorded_by,
            'deadline_missed_at' => $this->resource->deadline_missed_at?->toISOString(),
            'companies_notified_at' => $this->resource->companies_notified_at?->toISOString(),
            'resolved_at' => $this->resource->resolved_at?->toISOString(),
            'resolved_by' => $this->resource->resolved_by,
            'root_cause' => $this->resource->root_cause,
            'remediation_steps' => $this->resource->remediation_steps,
            'resolution_summary' => $this->resource->resolution_summary,
            'false_alarm_at' => $this->resource->false_alarm_at?->toISOString(),
            'false_alarm_by' => $this->resource->false_alarm_by,
            'false_alarm_reason' => $this->resource->false_alarm_reason,
            'false_alarm_findings' => $this->resource->false_alarm_findings,
            'companies' => $this->resource->companies->map(fn (BreachIncidentCompany $company): array => [
                'company_id' => $company->company_id,
                'name' => $company->company->name,
                'data_types' => $company->data_types,
                'estimated_count' => $company->estimated_count,
                'notified_at' => $company->notified_at?->toISOString(),
                'filing_requested_at' => $company->filing_requested_at?->toISOString(),
                'filing_requested_by' => $company->filing_requested_by,
                'filed_at' => $company->filed_at?->toISOString(),
            ])->all(),
            'total_estimated_count' => $this->resource->totalEstimatedCount(),
        ];
    }
}
