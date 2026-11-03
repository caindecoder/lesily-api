<?php

namespace Database\Factories;

use App\Companies\Entities\Company;
use App\Companies\Entities\CompanySettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySettings>
 */
class CompanySettingsFactory extends Factory
{
    protected $model = CompanySettings::class;

    /**
     * A new company's settings: every setting empty, so the company follows its
     * country (CompanySettingsProjector).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'timezone' => null,
            'currency' => null,
            'locale_default' => null,
            'fiscal_year_start_month' => null,
            'working_days_per_week' => null,
            'default_work_hours_per_day' => null,
        ];
    }

    /**
     * The four editable settings set to the company's own values, none equal to
     * Sri Lanka's.
     */
    public function ownValues(): static
    {
        return $this->state(fn (array $attributes): array => [
            'timezone' => 'Asia/Singapore',
            'fiscal_year_start_month' => 7,
            'working_days_per_week' => 6,
            'default_work_hours_per_day' => 7.5,
        ]);
    }
}
