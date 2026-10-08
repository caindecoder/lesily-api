<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Foundation\Http\Values\AdminRequestContext;
use App\Compliance\Dsar\UseCases\Commands\DownloadDsarExportCommand;
use App\Compliance\Dsar\UseCases\Interactors\DownloadDsarExportInteractor;

class DsarDownloadController extends Controller
{
    public function __invoke(Request $request, string $requestId, DownloadDsarExportInteractor $interactor): Response
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = DownloadDsarExportCommand::validateAndCreate([
            'requestId' => $requestId,
            'companyId' => $request->company()->getKey(),
        ]);

        $interactor->execute($command, $actor, AdminRequestContext::fromRequest($request));

        return response()->noContent();
    }
}
