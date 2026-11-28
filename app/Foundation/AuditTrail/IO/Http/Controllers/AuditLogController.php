<?php

namespace App\Foundation\AuditTrail\IO\Http\Controllers;

use stdClass;
use SplFileObject;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\LazyCollection;
use App\Foundation\AuditTrail\Enums\ExportFormat;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Foundation\AuditTrail\IO\Http\Resources\AuditLogRowResource;
use App\Foundation\AuditTrail\UseCases\Commands\ListAuditLogsCommand;
use App\Foundation\AuditTrail\IO\Http\Resources\AuditLogRowCollection;
use App\Foundation\AuditTrail\UseCases\Commands\ExportAuditLogsCommand;
use App\Foundation\AuditTrail\UseCases\Interactors\ListAuditLogsInteractor;
use App\Foundation\AuditTrail\UseCases\Interactors\ExportAuditLogsInteractor;

class AuditLogController extends Controller
{
    public function index(Request $request, ListAuditLogsInteractor $listAuditLogsInteractor): JsonResponse
    {
        $auditLogPage = $listAuditLogsInteractor->execute(ListAuditLogsCommand::fromQuery($request), $request->company());

        return (new AuditLogRowCollection($auditLogPage->stdClasses))
            ->additional(['meta' => ['retention' => ['from' => $auditLogPage->retentionStartDate->toDateString()]]])
            ->response($request);
    }

    public function export(Request $request, ExportAuditLogsInteractor $exportAuditLogsInteractor): StreamedResponse
    {
        $exportAuditLogsCommand = ExportAuditLogsCommand::fromQuery($request);
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $stdClasses = $exportAuditLogsInteractor->execute($exportAuditLogsCommand, $actor, RequestOrigin::fromRequest($request));

        return response()->streamDownload(
            fn () => $exportAuditLogsCommand->format === ExportFormat::Json ? $this->writeJson($stdClasses, $request) : $this->writeCsv($stdClasses, $request),
            'audit-logs-'.today()->toDateString().'.'.$exportAuditLogsCommand->format->value,
            ['Content-Type' => $exportAuditLogsCommand->format->contentType()],
        );
    }

    /**
     * @param  LazyCollection<int, stdClass>  $stdClasses
     */
    protected function writeJson(LazyCollection $stdClasses, Request $request): void
    {
        $separator = '';

        echo '[';

        foreach ($stdClasses as $stdClass) {
            echo $separator.json_encode((new AuditLogRowResource($stdClass))->toArray($request), JSON_THROW_ON_ERROR);
            $separator = ',';
        }

        echo ']';
    }

    /**
     * @param  LazyCollection<int, stdClass>  $stdClasses
     */
    protected function writeCsv(LazyCollection $stdClasses, Request $request): void
    {
        $splFileObject = new SplFileObject('php://output', 'w');
        $splFileObject->fputcsv(AuditLogRowResource::FIELDS, escape: '');

        foreach ($stdClasses as $stdClass) {
            $splFileObject->fputcsv(array_map($this->asText(...), (new AuditLogRowResource($stdClass))->toArray($request)), escape: '');
        }
    }

    /**
     * A spreadsheet runs a cell that starts with one of these as a formula; a leading apostrophe keeps it text.
     */
    protected function asText(mixed $cell): mixed
    {
        if (is_string($cell) && $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], strict: true)) {
            return "'".$cell;
        }

        return $cell;
    }
}
