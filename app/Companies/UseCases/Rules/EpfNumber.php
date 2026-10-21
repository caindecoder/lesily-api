<?php

namespace App\Companies\UseCases\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The EPF employer number: one zone letter, an optional "/", space or hyphen, then
 * up to six digits — the EPF Department's registration form ("Eg: A/099999", R-060).
 */
class EpfNumber implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Z][ \/-]?\d{1,6}$/i', $value) !== 1) {
            $fail('Invalid EPF number format.');
        }
    }
}
