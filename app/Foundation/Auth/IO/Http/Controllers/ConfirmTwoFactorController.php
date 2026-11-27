<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\Auth\IO\Http\Resources\RecoveryCodesResource;
use App\Foundation\Auth\UseCases\Commands\ConfirmTwoFactorCommand;
use App\Foundation\Auth\UseCases\Interactors\ConfirmTwoFactorInteractor;

class ConfirmTwoFactorController
{
    public function __invoke(Request $request, ConfirmTwoFactorInteractor $confirmTwoFactorInteractor): JsonResponse
    {
        $confirmTwoFactorCommand = ConfirmTwoFactorCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        $codes = $confirmTwoFactorInteractor->execute($user, $confirmTwoFactorCommand, RequestOrigin::fromRequest($request));

        return (new RecoveryCodesResource($codes))->response();
    }
}
