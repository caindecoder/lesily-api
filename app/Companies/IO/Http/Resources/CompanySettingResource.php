<?php

namespace App\Companies\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Companies\Entities\CompanySettings;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The company-settings sub-object of CompanyResource. All six built
 * company_settings columns are view-visible (s117); only the four
 * UC-1-033-named fields are editable — enforced at the update whitelist,
 * not here.
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
        return [
            'timezone' => $this->resource->timezone,
            'currency' => $this->resource->currency,
            'locale_default' => $this->resource->locale_default,
            'fiscal_year_start_month' => $this->resource->fiscal_year_start_month,
            'working_days_per_week' => $this->resource->working_days_per_week,
            'default_work_hours_per_day' => $this->resource->default_work_hours_per_day,
        ];
    }
}
