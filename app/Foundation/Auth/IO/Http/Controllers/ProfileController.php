<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\IO\Http\Resources\MeResource;
use App\Foundation\Auth\UseCases\Commands\UpdateUserProfileCommand;

class ProfileController extends Controller
{
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user());
    }

    public function update(Request $request): MeResource
    {
        UpdateUserProfileCommand::validateAndCreate($request);

        return new MeResource($request->user());
    }
}
