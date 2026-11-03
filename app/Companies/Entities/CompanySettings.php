<?php

namespace App\Companies\Entities;

use Illuminate\Database\Eloquent\Model;
use Database\Factories\CompanySettingsFactory;
use App\Foundation\CountryConfig\Entities\Country;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * Per-tenant company settings read model (ERD-1-02 `company_settings`). An
 * empty setting follows the company's country; a value is the company's own
 * (UC-1-032/033).
 */
class CompanySettings extends Model
{
    /** @use HasFactory<CompanySettingsFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The six settings a company follows from its country or sets itself.
     *
     * @var list<string>
     */
    public const array SETTINGS = [
        'timezone',
        'currency',
        'locale_default',
        'fiscal_year_start_month',
        'working_days_per_week',
        'default_work_hours_per_day',
    ];

    protected $table = 'company_settings';

    protected $fillable = [
        'id',
        'company_id',
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

    /**
     * The company's country, reached by its code (`companies.country`).
     *
     * @return HasOneThrough<Country, Company, $this>
     */
    public function country(): HasOneThrough
    {
        return $this->hasOneThrough(Country::class, Company::class, 'id', 'code', 'company_id', 'country');
    }

    /**
     * The company's own value for $setting, otherwise its country's.
     */
    public function valueThatApplies(string $setting): mixed
    {
        return $this->getAttribute($setting) ?? $this->country?->getAttribute($setting);
    }

    protected static function newFactory(): CompanySettingsFactory
    {
        return CompanySettingsFactory::new();
    }
}
