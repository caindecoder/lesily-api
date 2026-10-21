<?php

namespace App\Companies\Memberships\UseCases\Rules;

use Closure;
use Propaganistas\LaravelPhone\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A valid Sri Lankan phone number, local or +94. The country is fixed here: the
 * package's `phone` rule also reads a country from the request, so a client could widen it.
 */
class SriLankanPhoneNumber implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isSriLankan(new PhoneNumber($value, 'LK'))) {
            $fail('The :attribute must be a valid phone number.');
        }
    }

    protected function isSriLankan(PhoneNumber $phone): bool
    {
        return $phone->isValid() && $phone->isOfCountry('LK');
    }
}
