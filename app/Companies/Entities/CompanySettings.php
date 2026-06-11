<?php

namespace App\Companies\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Per-tenant company settings read model (ERD-1-02 `company_settings`), seeded
 * from the country defaults at creation and tenant-editable later (UC-1-032/033).
 */
class CompanySettings extends Model
{
    use HasUuids;

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
}
