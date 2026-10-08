<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Foundation\Http\Values\AdminRequestContext;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Compliance\Dsar\UseCases\Commands\DownloadDsarExportCommand;
use App\Compliance\Dsar\UseCases\Interactors\DownloadDsarExportInteractor;

class DsarDownloadController extends Controller
{
    public function __invoke(Request $request, string $requestId, DownloadDsarExportInteractor $interactor): StreamedResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $command = DownloadDsarExportCommand::validateAndCreate([
            'requestId' => $requestId,
            'companyId' => $request->company()->getKey(),
        ]);

        $dsarRequest = $interactor->execute($command, $actor, AdminRequestContext::fromRequest($request));

        return Storage::disk('dsar')->download(
            $dsarRequest->exportFilePath(),
            'lesily-data-export-'.$dsarRequest->fulfilledAt()->format('Y-m-d').'.zip',
            ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
