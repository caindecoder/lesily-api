<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Compliance\Dsar\UseCases\Commands\DownloadDsarExportCommand;
use App\Compliance\Dsar\UseCases\Interactors\DownloadDsarExportInteractor;

class DsarDownloadController extends Controller
{
    public function __invoke(Request $request, string $requestId, DownloadDsarExportInteractor $downloadDsarExportInteractor): StreamedResponse
    {
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $downloadDsarExportCommand = DownloadDsarExportCommand::validateAndCreate([
            'dsarRequest' => $requestId,
            'company' => $request->company(),
        ]);

        $dsarExportFile = $downloadDsarExportInteractor->execute($downloadDsarExportCommand, $actor, RequestOrigin::fromRequest($request));

        return Storage::disk('dsar')->download(
            $dsarExportFile->path,
            $dsarExportFile->fileName(),
            ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
