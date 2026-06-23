<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\IO\Http\Resources\RecoveryCodesResource;
use App\Foundation\Auth\UseCases\Commands\RegenerateRecoveryCodesCommand;
use App\Foundation\Auth\UseCases\Interactors\RegenerateRecoveryCodesInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/2fa/recovery-codes — rotating the
 * recovery codes after a password re-auth (UC-1-137). Validation lives on the Command,
 * the password check + code rotation + marker in the interactor; the controller only
 * resolves the authenticated user and shapes the one-time 200 RecoveryCodesResource.
 */
class RegenerateRecoveryCodesController extends Controller
{
    public function __invoke(Request $request, RegenerateRecoveryCodesInteractor $interactor): JsonResponse
    {
        $command = RegenerateRecoveryCodesCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        $codes = $interactor->execute($user, $command);

        return (new RecoveryCodesResource($codes))->response();
    }
}
