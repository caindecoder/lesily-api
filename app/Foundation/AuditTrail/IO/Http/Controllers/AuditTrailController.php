<?php

namespace App\Foundation\AuditTrail\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\UseCases\Commands\AuditTrailFilters;

class AuditTrailController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        AuditTrailFilters::fromQuery($request);

        return new JsonResponse(['data' => []]);
    }
}
