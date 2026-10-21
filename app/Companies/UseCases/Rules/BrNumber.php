<?php

namespace App\Companies\UseCases\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The BR number (R-060, ruling S1): a number that starts like a Registrar of Companies
 * number must be one; any other — a provincial business-name number, which has no
 * national format — must be letters, digits, spaces, "/" and "-" with at least one digit.
 */
class BrNumber implements ValidationRule
{
    protected const COMPANY_START = '/^(PV|PB|PQ|GA|GL)[ -]?\d/i';

    protected const COMPANY = '/^(PV|PB|PQ|GA|GL)[ -]?\d{1,8}( [A-Z]{2}(\/[A-Z]{2})?)?$/i';

    protected const LEGACY_START = '/^N\(/i';

    protected const LEGACY = '/^N\(PVS\) ?\d{1,8}$/i';

    protected const LOCAL = '/^(?=.*\d)[A-Z0-9][A-Z0-9 \/-]*$/i';

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match($this->patternFor($value), $value) !== 1) {
            $fail('Invalid BR number format.');
        }
    }

    protected function patternFor(string $value): string
    {
        if (preg_match(self::COMPANY_START, $value) === 1) {
            return self::COMPANY;
        }

        if (preg_match(self::LEGACY_START, $value) === 1) {
            return self::LEGACY;
        }

        return self::LOCAL;
    }
}
