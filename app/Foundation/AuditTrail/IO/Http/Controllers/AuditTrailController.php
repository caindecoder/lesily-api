<?php

namespace App\Foundation\AuditTrail\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class AuditTrailController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
