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
            'companies' => $this->resource->companies->map(fn (BreachIncidentCompany $company): array => [
                'company_id' => $company->company_id,
                'name' => $company->company->name,
                'data_types' => $company->data_types,
                'estimated_count' => $company->estimated_count,
            ])->all(),
            'total_estimated_count' => $this->resource->totalEstimatedCount(),
        ];
    }
}
