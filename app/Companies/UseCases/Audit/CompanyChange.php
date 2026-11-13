<?php

namespace App\Companies\UseCases\Audit;

use App\Companies\Entities\Company;
use App\Companies\Enums\ResettableSetting;
use App\Companies\Entities\CompanySettings;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\FieldChanges;

/**
 * One kind of change a company update made: its details, its own settings, or one
 * setting put back on the country default.
 */
readonly class CompanyChange
{
    public function __construct(
        public Company $company,
        public AuditAction $action,
        public FieldChanges $changes,
    ) {}

    /**
     * A setting put back on its country's default: its own value → the country's.
     */
    public static function resetOf(Company $company, CompanySettings $settings, ResettableSetting $setting): self
    {
        return new self($company, AuditAction::CompanySettingReset, FieldChanges::between(
            [$setting->value => $settings->getAttribute($setting->value)],
            [$setting->value => $settings->countryDefault($setting->value)],
        ));
    }
}
