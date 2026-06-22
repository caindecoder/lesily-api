<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Foundation\Auth\IO\Http\Resources\RecoveryCodesResource;
use App\Foundation\Auth\UseCases\Commands\ConfirmTwoFactorCommand;
use App\Foundation\Auth\UseCases\Interactors\ConfirmTwoFactorInteractor;

class ConfirmTwoFactorController
{
    public function __invoke(Request $request, ConfirmTwoFactorInteractor $interactor): JsonResponse
    {
        $command = ConfirmTwoFactorCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        $codes = $interactor->execute($user, $command);

        return (new RecoveryCodesResource($codes))->response();
    }
}
