<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Compliance\Dsar\IO\Http\Resources\DsarRequestAdminResource;
use App\Compliance\Dsar\UseCases\Interactors\ReviewDsarRequestAsAdminInteractor;

class PlatformAdminDsarRequestsController extends Controller
{
    public function show(Request $request, string $requestId, ReviewDsarRequestAsAdminInteractor $interactor): DsarRequestAdminResource
    {
        /** @var User $admin — guaranteed by auth:sanctum */
        $admin = $request->user();

        return new DsarRequestAdminResource($interactor->execute($requestId, $admin, AdminRequestContext::fromRequest($request)));
    }
}
