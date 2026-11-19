<?php

namespace App\Companies\IO\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Companies\Entities\CompanyDataProtectionOfficer;

/**
 * The company's data protection officer as recorded (UC-1-146) — the values read
 * back through the model's encrypted casts.
 *
 * @property-read CompanyDataProtectionOfficer $resource
 */
class DataProtectionOfficerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
        ];
    }
}
