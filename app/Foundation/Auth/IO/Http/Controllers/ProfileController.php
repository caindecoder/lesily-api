<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\IO\Http\Resources\MeResource;

class ProfileController extends Controller
{
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user());
    }
}
