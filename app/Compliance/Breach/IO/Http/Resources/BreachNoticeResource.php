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
        return [
            'breach_id' => $this->resource->breach_incident_id,
        ];
    }
}
