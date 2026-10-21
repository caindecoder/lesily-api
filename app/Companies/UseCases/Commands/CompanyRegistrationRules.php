<?php

namespace App\Companies\UseCases\Commands;

/**
 * Rule-builders and messages for the company-registration fields, applied by
 * RegisterOwnerCommand at the HTTP seam. BR/EPF global uniqueness is a 409
 * conflict enforced by RegistrationUniquenessGuard, not a 422 validation rule here.
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

    /**
     * @return array<string, string>
     */
    protected static function companyRegistrationMessages(): array
    {
        return [
            'brNumber.required' => 'The BR (Business Registration) number is required.',
            'epfNumber.required' => 'The EPF employer number is required.',
        ];
    }
}
