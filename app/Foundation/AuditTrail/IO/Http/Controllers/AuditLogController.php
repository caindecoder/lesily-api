<?php

namespace App\Foundation\AuditTrail\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogFilters;
use App\Foundation\AuditTrail\IO\Http\Resources\AuditLogRowCollection;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogExportFilters;
use App\Foundation\AuditTrail\UseCases\Interactors\ListAuditLogsInteractor;

class AuditLogController extends Controller
{
    public function index(Request $request, ListAuditLogsInteractor $interactor): JsonResponse
    {
        $page = $interactor->execute(AuditLogFilters::fromQuery($request), $request->company());

        return (new AuditLogRowCollection($page->rows))
            ->additional(['meta' => ['retention' => ['from' => $page->retentionFrom->toDateString()]]])
            ->response($request);
    }

    public function export(Request $request): StreamedResponse
    {
        AuditLogExportFilters::fromQuery($request);

        return response()->streamDownload(
            fn () => null,
            'audit-logs-'.today()->toDateString().'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
