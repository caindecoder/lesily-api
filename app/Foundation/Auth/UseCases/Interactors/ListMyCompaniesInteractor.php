<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use stdClass;
use App\Models\User;
use Illuminate\Support\Collection;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;

/**
 * Lists the authenticated user's own live company memberships (UC-1-034) — the
 * company-switcher read. Repository-direct with no aggregate and no events
 * (slice-companies §1): switching itself is client-side via X-Company-Context,
 * so this read is the whole server-side surface. Own-only is inherent (the
 * repository scopes by the user id).
 */
class ListMyCompaniesInteractor
{
    public function __construct(private readonly CompanyUserRepositoryInterface $memberships) {}

    /**
     * @return Collection<int, stdClass>
     */
    public function execute(User $user): Collection
    {
        return $this->memberships->listForUser($user->getKey());
    }
}
