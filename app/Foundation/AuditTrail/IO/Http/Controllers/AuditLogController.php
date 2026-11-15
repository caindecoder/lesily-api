<?php

namespace App\Foundation\AuditTrail\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\UseCases\Commands\AuditLogFilters;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        AuditLogFilters::fromQuery($request);

        return response()->json(['data' => []]);
    }
}
