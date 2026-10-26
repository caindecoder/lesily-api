<?php

namespace App\Companies\Memberships\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Companies\Entities\CompanyUser;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One company's owner emergency contact (UC-1-029): {name, email, phone, relationship}.
 *
 * @property-read CompanyUser $resource
 */
class EmergencyContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->emergency_contact_name,
            'email' => $this->resource->emergency_contact_email,
            'phone' => $this->resource->emergency_contact_phone,
            'relationship' => $this->resource->emergency_contact_relationship,
        ];
    }
}
