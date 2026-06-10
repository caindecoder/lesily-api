<?php

namespace App\Companies\UseCases\Commands;

/**
 * Shared rule-builders for the company-registration commands (this command now;
 * RegisterOwnerCommand reuses them at the HTTP seam — slice increments 04/05,
 * where the BR/EPF Rule::unique guards are added).
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
     * @return array<int, string>
     */
    protected static function ownerUserIdRules(): array
    {
        return ['required', 'uuid'];
    }
}
