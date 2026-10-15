<?php

namespace Tests\Unit\Conventions\Fixtures\ModelKeyReads;

use stdClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read stdClass $resource
 */
final class RowResourceRead extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->id];
    }
}
