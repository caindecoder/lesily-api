<?php

namespace App\Foundation\UseCases\Rules;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * `from,to` — exactly two comma-separated ISO-8601 (Y-m-d) dates that exist on the
 * calendar (api-conventions §10.1). The property's `bail` + `string` rules precede it.
 */
class IsoDateRange implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var string $value */
        $dates = explode(',', $value);

        if (count($dates) !== 2 || ! $this->isCalendarDate($dates[0]) || ! $this->isCalendarDate($dates[1])) {
            $fail("The {$attribute} filter must be two ISO-8601 (YYYY-MM-DD) dates separated by a comma.");
        }
    }

    /**
     * 2026-02-31 has the shape but would roll over to March, so the date must read back unchanged.
     */
    protected function isCalendarDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
