<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use App\Foundation\Auth\UseCases\Commands\SetDefaultCompanyCommand;

/**
 * Set the user's default company (UC-1-139) — a plain users.default_company_id
 * preference write (NOT event-sourced; slice-companies B2). The guard is the
 * use case's business rule (E07): only a LIVE membership may become the default
 * — non-member, unknown and removed-membership targets all collapse to the same
 * 422 so a refusal never leaks whether a company id exists (B3). The companies()
 * relation already excludes soft-deleted pivots (wherePivotNull, UC-1-024).
 */
class SetDefaultCompanyInteractor
{
    public function execute(User $user, SetDefaultCompanyCommand $command): void
    {
        if (! $user->companies()->whereKey($command->companyId)->exists()) {
            throw ValidationException::withMessages([
                'companyId' => 'The selected company is invalid.',
            ]);
        }

        $user->default_company_id = $command->companyId;
        $user->save();
    }
}
