<?php

namespace App\Foundation\Auth\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Set the authenticated user's default company (UC-1-139, slice-companies B3).
 * Format-only bounds here (uuid leaks nothing); the live-membership guard lives
 * in the interactor so every non-live target collapses to the same 422 — an
 * exists-rule would leak company-id existence.
 */
class SetDefaultCompanyCommand extends Data
{
    public string $companyId;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'companyId' => ['required', 'string', 'uuid'],
        ];
    }
}
