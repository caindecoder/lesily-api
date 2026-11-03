<?php

namespace App\Foundation\CountryConfig\Entities;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A supported country and the six company-setting defaults its companies follow
 * (ERD-1-10 `countries`), seeded from `database/seeders/data/countries.yaml`.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $timezone
 * @property string $currency
 * @property string $locale_default
 * @property int $fiscal_year_start_month
 * @property int $working_days_per_week
 * @property float $default_work_hours_per_day
 */
class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'countries';

    protected $fillable = [
        'id',
        'code',
        'name',
        'timezone',
        'currency',
        'locale_default',
        'fiscal_year_start_month',
        'working_days_per_week',
        'default_work_hours_per_day',
    ];

    protected $casts = [
        'fiscal_year_start_month' => 'integer',
        'working_days_per_week' => 'integer',
        'default_work_hours_per_day' => 'float',
    ];

    protected static function newFactory(): CountryFactory
    {
        return CountryFactory::new();
    }
}
