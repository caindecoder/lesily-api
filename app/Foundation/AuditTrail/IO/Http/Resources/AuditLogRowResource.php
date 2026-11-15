<?php

namespace App\Foundation\AuditTrail\IO\Http\Resources;

use stdClass;
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
        ];
    }
}
