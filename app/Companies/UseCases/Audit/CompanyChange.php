<?php

namespace App\Companies\UseCases\Audit;

use App\Companies\Entities\Company;
use App\Companies\Enums\ResettableSetting;
use App\Companies\Entities\CompanySettings;
use App\Companies\Events\CompanySettingReset;
use App\Companies\Events\CompanyDetailsUpdated;
use App\Companies\Events\CompanySettingsUpdated;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\FieldChanges;

/**
 * One kind of change a company update made — its details, its own settings, or one
 * setting put back on the country default — with the event that recorded it.
 */
readonly class CompanyChange
{
    /** @param class-string $eventClass */
    private function __construct(
        public Company $company,
        public AuditAction $action,
        public string $eventClass,
        public FieldChanges $changes,
    ) {}

    public static function detailsOf(Company $company, FieldChanges $changes): self
    {
        return new self($company, AuditAction::CompanyDetailsUpdated, CompanyDetailsUpdated::class, $changes);
    }

    public static function settingsOf(Company $company, FieldChanges $changes): self
    {
        return new self($company, AuditAction::CompanySettingsUpdated, CompanySettingsUpdated::class, $changes);
    }

    /**
     * A setting put back on its country's default: its own value → the country's.
     */
    public static function resetOf(Company $company, CompanySettings $settings, ResettableSetting $setting): self
    {
        return new self($company, AuditAction::CompanySettingReset, CompanySettingReset::class, FieldChanges::between(
            [$setting->value => $settings->getAttribute($setting->value)],
            [$setting->value => $settings->countryDefault($setting->value)],
        ));
    }
}
