<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Foundation\Auth\IO\Http\Resources\TwoFactorSetupResource;
use App\Foundation\Auth\UseCases\Interactors\EnableTwoFactorInteractor;

class EnableTwoFactorController
{
    public function __invoke(Request $request, EnableTwoFactorInteractor $interactor): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new TwoFactorSetupResource($interactor->execute($user)))->response();
    }
}
