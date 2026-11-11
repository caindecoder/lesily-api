<?php

namespace App\Foundation\AuditTrail\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\UseCases\Commands\AuditTrailFilters;
use App\Foundation\AuditTrail\IO\Http\Resources\AuditEntryCollection;
use App\Foundation\AuditTrail\UseCases\Interactors\ListAuditTrailInteractor;

class AuditTrailController extends Controller
{
    public function index(Request $request, ListAuditTrailInteractor $interactor): JsonResponse
    {
        $entries = $interactor->execute(AuditTrailFilters::fromQuery($request), $request->company());

        return (new AuditEntryCollection($entries))->response($request);
    }
}
