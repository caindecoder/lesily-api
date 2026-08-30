<?php

namespace App\Companies\UseCases\Commands;

/**
 * Rule-builders for the company-registration fields, applied by
 * RegisterOwnerCommand at the HTTP seam. These are shape-level only:
 * BR/EPF global uniqueness is a 409 conflict enforced by RegistrationUniquenessGuard
 * (slice #05), not a 422 validation rule here.
 */
trait CompanyRegistrationRules
{
    /**
     * @return array<int, string>
     */
    protected static function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, string>
     */
    protected static function brNumberRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, string>
     */
    protected static function epfNumberRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, string>
     */
    protected static function countryRules(): array
    {
        return ['required', 'string', 'size:2'];
    }
}
