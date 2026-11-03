<?php

namespace App\Companies\IO\Http\Resources;

use Illuminate\Http\Request;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanySettings;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The company's settings view (UC-1-056 / 057): its country and, per setting,
 * the country default, the company's own value, the value that applies and
 * whether it follows the country.
 *
 * @property-read Company $resource
 */
class CompanySettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = $this->resource->settings;

        return [
            'country' => [
                'code' => $this->resource->country,
                'name' => $settings->country->name,
            ],
            'settings' => collect(CompanySettings::SETTINGS)
                ->mapWithKeys(fn (string $setting): array => [$setting => [
                    'country_default' => $settings->countryDefault($setting),
                    'own_value' => $settings->getAttribute($setting),
                    'applies' => $settings->valueThatApplies($setting),
                    'is_following_country' => $settings->followsTheCountry($setting),
                ]])
                ->all(),
        ];
    }
}
