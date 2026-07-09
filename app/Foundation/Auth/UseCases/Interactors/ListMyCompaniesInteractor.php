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
        // Deriving the default flag is the use case's rule (UC-1-139 B2): comparing
        // live rows against users.default_company_id means a stale default (removed
        // membership) simply flags nothing — the read-time fallback, no cleanup job.
        // Block closure deliberately: each() stops on a false return, and a
        // non-default row's assignment evaluates to exactly that false.
        return $this->memberships->listForUser($user->getKey())
            ->each(function (stdClass $row) use ($user): void {
                $row->is_default = $row->id === $user->default_company_id;
            });
    }
}
