<?php

namespace App\Companies\Aggregates;

use App\Companies\Enums\ResettableSetting;
use App\Companies\Entities\CompanySettings;

/**
 * A company's resettable settings as its interactor read them under the row
 * lock — what the Company aggregate decides a reset or an update against.
 */
class SettingsBaseline
{
    /**
     * @param  array<string, mixed>  $ownValues  keyed by ResettableSetting value; null = follows the country
     */
    private function __construct(protected array $ownValues) {}

    public static function of(CompanySettings $settings): self
    {
        $ownValues = [];

        foreach (ResettableSetting::cases() as $setting) {
            $ownValues[$setting->value] = $settings->getAttribute($setting->value);
        }

        return new self($ownValues);
    }

    public function hasOwnValue(ResettableSetting $setting): bool
    {
        return $this->ownValues[$setting->value] !== null;
    }
}
