<?php

namespace App\Foundation\AuditTrail\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\IO\Http\Resources\AuditEntryCollection;
use App\Foundation\AuditTrail\UseCases\Commands\ListAuditTrailCommand;
use App\Foundation\AuditTrail\UseCases\Interactors\ListAuditTrailInteractor;

class AuditTrailController extends Controller
{
    public function index(Request $request, ListAuditTrailInteractor $listAuditTrailInteractor): JsonResponse
    {
        $auditEntries = $listAuditTrailInteractor->execute(ListAuditTrailCommand::fromQuery($request), $request->company());

        return (new AuditEntryCollection($auditEntries))->response($request);
    }
}
