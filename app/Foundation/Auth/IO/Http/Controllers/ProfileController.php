<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\IO\Http\Resources\MeResource;
use App\Foundation\Auth\UseCases\Commands\UpdateUserProfileCommand;
use App\Foundation\Auth\UseCases\Interactors\UpdateUserProfileInteractor;

class ProfileController extends Controller
{
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user());
    }

    public function update(Request $request, UpdateUserProfileInteractor $interactor): MeResource
    {
        $command = UpdateUserProfileCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        return new MeResource($interactor->execute($user, $command));
    }
}
