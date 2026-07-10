<?php

namespace Database\Factories;

use App\Companies\Entities\Company;
use App\Companies\Support\CountryDefaults;
use App\Companies\Entities\CompanySettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySettings>
 */
class CompanySettingsFactory extends Factory
{
    protected $model = CompanySettings::class;

    /**
     * The production genesis seeds settings from the country defaults
     * (CompanySettingsProjector / CountryDefaults) — the factory mirrors that
     * invariant for the LK launch market.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $defaults = CountryDefaults::for('LK');

        return [
            'company_id' => Company::factory(),
            'timezone' => $defaults['timezone'],
            'currency' => $defaults['currency'],
            'locale_default' => $defaults['localeDefault'],
            'fiscal_year_start_month' => $defaults['fiscalYearStartMonth'],
            'working_days_per_week' => $defaults['workingDaysPerWeek'],
            'default_work_hours_per_day' => $defaults['defaultWorkHoursPerDay'],
        ];
    }
}
