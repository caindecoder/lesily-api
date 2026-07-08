<?php

namespace App\Foundation\Auth\IO\Http\Resources;

use stdClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One switcher-list row (UC-1-034, slice-companies B4) — the membership's
 * company as the repository's aliased columns.
 *
 * @property-read stdClass $resource
 */
class MyCompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'status' => $this->resource->status,
        ];
    }
}
