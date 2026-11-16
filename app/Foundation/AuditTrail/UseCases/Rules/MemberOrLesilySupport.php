<?php

namespace App\Foundation\AuditTrail\UseCases\Rules;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A member's id, or the word for every Lesily staff row (AR24). The property's
 * `bail` + `string` rules precede it.
 */
class MemberOrLesilySupport implements ValidationRule
{
    public const string LESILY_SUPPORT = 'lesily_support';

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var string $value */
        if ($value !== self::LESILY_SUPPORT && ! Str::isUuid($value)) {
            $fail("The {$attribute} filter must be a member's id or lesily_support.");
        }
    }
}
