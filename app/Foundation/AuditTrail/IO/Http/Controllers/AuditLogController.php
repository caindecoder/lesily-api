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
    public function index(Request $request, ListAuditLogsInteractor $interactor): JsonResponse
    {
        $page = $interactor->execute(ListAuditLogsCommand::fromQuery($request), $request->company());

        return (new AuditLogRowCollection($page->rows))
            ->additional(['meta' => ['retention' => ['from' => $page->retentionFrom->toDateString()]]])
            ->response($request);
    }

    public function export(Request $request, ExportAuditLogsInteractor $interactor): StreamedResponse
    {
        $filters = ExportAuditLogsCommand::fromQuery($request);
        /** @var User $actor — guaranteed by auth:sanctum */
        $actor = $request->user();

        $rows = $interactor->execute($filters, $actor, RequestOrigin::fromRequest($request));

        return response()->streamDownload(
            fn () => $filters->format === ExportFormat::Json ? $this->writeJson($rows, $request) : $this->writeCsv($rows, $request),
            'audit-logs-'.today()->toDateString().'.'.$filters->format->value,
            ['Content-Type' => $filters->format->contentType()],
        );
    }

    /**
     * @param  LazyCollection<int, stdClass>  $rows
     */
    protected function writeJson(LazyCollection $rows, Request $request): void
    {
        $separator = '';

        echo '[';

        foreach ($rows as $row) {
            echo $separator.json_encode((new AuditLogRowResource($row))->toArray($request), JSON_THROW_ON_ERROR);
            $separator = ',';
        }

        echo ']';
    }

    /**
     * @param  LazyCollection<int, stdClass>  $rows
     */
    protected function writeCsv(LazyCollection $rows, Request $request): void
    {
        $output = new SplFileObject('php://output', 'w');
        $output->fputcsv(AuditLogRowResource::FIELDS, escape: '');

        foreach ($rows as $row) {
            $output->fputcsv(array_map($this->asText(...), (new AuditLogRowResource($row))->toArray($request)), escape: '');
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
