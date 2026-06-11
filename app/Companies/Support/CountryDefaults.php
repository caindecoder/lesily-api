<?php

namespace App\Companies\Support;

use App\Companies\Exceptions\UnsupportedCountryException;

/**
 * Chunk-1 source for per-country company-setting defaults (UC-1-031 step 7 /
 * three-tier inheritance: Country → Company → Employee). Hardcoded to the SL
 * launch market — the Country Configuration module (cd-country-config) replaces
 * this map when multi-country lands; signups are LK-only until then (WF-1-006
 * shows other countries disabled).
 */
class CountryDefaults
{
    /**
     * @return array{timezone: string, currency: string, localeDefault: string, fiscalYearStartMonth: int, workingDaysPerWeek: int, defaultWorkHoursPerDay: float}
     */
    public static function for(string $country): array
    {
        return match ($country) {
            'LK' => [
                'timezone' => 'Asia/Colombo',
                'currency' => 'LKR',
                'localeDefault' => 'en',
                'fiscalYearStartMonth' => 1,
                'workingDaysPerWeek' => 5,
                'defaultWorkHoursPerDay' => 8.0,
            ],
            default => throw UnsupportedCountryException::forCountry($country),
        };
    }
}
