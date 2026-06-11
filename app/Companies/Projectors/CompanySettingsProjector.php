<?php

namespace App\Companies\Projectors;

use App\Companies\Entities\CompanySettings;
use App\Companies\Events\CompanySettingsCreated;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Mirrors the settings events into the `company_settings` read model (ERD-1-02) —
 * a narrow listener, deliberately separate from CompanyProjector (CD-1-02).
 */
class CompanySettingsProjector extends Projector
{
    public function onCompanySettingsCreated(CompanySettingsCreated $event): void
    {
        CompanySettings::query()->create([
            'id' => $event->settingsId,
            'company_id' => $event->companyId,
            'timezone' => $event->timezone,
            'currency' => $event->currency,
            'locale_default' => $event->localeDefault,
            'fiscal_year_start_month' => $event->fiscalYearStartMonth,
            'working_days_per_week' => $event->workingDaysPerWeek,
            'default_work_hours_per_day' => $event->defaultWorkHoursPerDay,
        ]);
    }
}
