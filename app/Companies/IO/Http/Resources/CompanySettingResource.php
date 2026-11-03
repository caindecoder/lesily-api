<?php

namespace App\Companies\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Companies\Entities\CompanySettings;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The company-settings sub-object of CompanyResource: the value that applies
 * for each of the six settings. Only the four UC-1-033-named fields are
 * editable — enforced at the update whitelist, not here.
 *
 * @property-read CompanySettings $resource
 */
class CompanySettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return collect(CompanySettings::SETTINGS)
            ->mapWithKeys(fn (string $setting): array => [$setting => $this->resource->valueThatApplies($setting)])
            ->all();
    }
}
