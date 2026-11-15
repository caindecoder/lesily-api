<?php

namespace App\Foundation\AuditTrail\IO\Http\Resources;

use stdClass;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read stdClass $resource
 */
class AuditLogRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'time' => $this->isoTime($this->resource->time),
            'first_seen_at' => $this->isoTime($this->resource->first_seen_at),
            'occurrences' => $this->resource->occurrences,
            'user_id' => $this->resource->user_id,
            'user_name' => $this->resource->user_name,
        ];
    }

    protected function isoTime(string $storedTime): string
    {
        return CarbonImmutable::parse($storedTime, 'UTC')->toISOString();
    }
}
