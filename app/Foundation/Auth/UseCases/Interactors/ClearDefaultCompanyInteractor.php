<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;

/**
 * Clear the user's default company (UC-1-139 alt-1a) — nulls the plain
 * users.default_company_id preference. Idempotent by construction: clearing
 * an already-null default is the same write. No Command — the request
 * carries no input.
 */
class ClearDefaultCompanyInteractor
{
    public function execute(User $user): void
    {
        $user->default_company_id = null;
        $user->save();
    }
}
