<?php

namespace Database\Factories;

use App\Foundation\CountryConfig\Entities\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A supported country other than the seeded Sri Lanka row (Maldives), so a test
 * can add one beside it without colliding on `code`.
 *
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'MV',
            'name' => 'Maldives',
            'timezone' => 'Indian/Maldives',
            'currency' => 'MVR',
            'locale_default' => 'en',
            'fiscal_year_start_month' => 1,
            'working_days_per_week' => 5,
            'default_work_hours_per_day' => 8.0,
        ];
    }
}
