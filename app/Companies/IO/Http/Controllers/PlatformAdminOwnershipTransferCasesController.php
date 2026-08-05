<?php

namespace App\Companies\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Companies\UseCases\Commands\OpenOwnershipTransferCaseCommand;
use App\Companies\UseCases\Interactors\OpenOwnershipTransferCaseInteractor;

/**
 * The platform-admin ownership-transfer case surface (slice-11R spec §3).
 * Cross-tenant by design: auth:sanctum + platform.admin only — no
 * auth.company, no permission gate (EnsurePermission needs a company context
 * this family deliberately lacks). Non-allowlisted callers are 404ed by the
 * middleware before any method here runs.
 */
class PlatformAdminOwnershipTransferCasesController
{
    public function store(Request $request, OpenOwnershipTransferCaseInteractor $interactor): void
    {
        /** @var User $admin */
        $admin = $request->user();

        $interactor->execute(
            OpenOwnershipTransferCaseCommand::validateAndCreate($request),
            $admin,
            AdminRequestContext::fromRequest($request),
        );
    }
}
