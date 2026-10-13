<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use RuntimeException;
use App\Http\Controllers\Controller;
use App\Compliance\Dsar\UseCases\Interactors\ReviewDsarRequestAsAdminInteractor;

class PlatformAdminDsarRequestsController extends Controller
{
    public function show(string $requestId, ReviewDsarRequestAsAdminInteractor $interactor): never
    {
        $interactor->execute($requestId);

        throw new RuntimeException('Not implemented');
    }
}
