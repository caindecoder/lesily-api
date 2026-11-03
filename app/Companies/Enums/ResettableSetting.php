<?php

namespace App\Companies\Enums;

/**
 * The company settings that can go back to following the country (UC-1-058).
 * Each value is its `company_settings` column; currency and the default
 * language always follow, so they are not here.
 */
enum ResettableSetting: string
{
    case Timezone = 'timezone';
    case FiscalYearStartMonth = 'fiscal_year_start_month';
    case WorkingDaysPerWeek = 'working_days_per_week';
    case DefaultWorkHoursPerDay = 'default_work_hours_per_day';
}
