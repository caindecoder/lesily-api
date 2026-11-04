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
     * @param  array<string, mixed>  $countryDefaults  keyed by ResettableSetting value
     */
    private function __construct(protected array $ownValues, protected array $countryDefaults) {}

    public static function of(CompanySettings $settings): self
    {
        $ownValues = [];
        $countryDefaults = [];

        foreach (ResettableSetting::cases() as $setting) {
            $ownValues[$setting->value] = $settings->getAttribute($setting->value);
            $countryDefaults[$setting->value] = $settings->countryDefault($setting->value);
        }

        return new self($ownValues, $countryDefaults);
    }

    public function hasOwnValue(ResettableSetting $setting): bool
    {
        return $this->ownValues[$setting->value] !== null;
    }

    /**
     * The sent settings that carry their country's default over an own value.
     *
     * @param  array<string, int|float|string>  $sent  keyed by ResettableSetting value
     * @return list<ResettableSetting>
     */
    public function settingsFollowingAgain(array $sent): array
    {
        $following = [];

        foreach ($sent as $column => $value) {
            $setting = ResettableSetting::from($column);

            if ($this->isCountryDefault($column, $value) && $this->hasOwnValue($setting)) {
                $following[] = $setting;
            }
        }

        return $following;
    }

    /**
     * The sent settings that become the company's own values — every one not
     * equal to its country's default.
     *
     * @param  array<string, int|float|string>  $sent  keyed by ResettableSetting value
     * @return array<string, int|float|string>
     */
    public function ownValueChanges(array $sent): array
    {
        $changes = [];

        foreach ($sent as $column => $value) {
            if (! $this->isCountryDefault($column, $value)) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    protected function isCountryDefault(string $column, int|float|string $value): bool
    {
        return $value === $this->countryDefaults[$column];
    }
}
