<?php

namespace App\Compliance\Breach\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Compliance\Breach\UseCases\Commands\BreachNoticeFilters;

class BreachNoticesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        BreachNoticeFilters::fromQuery($request);

        return response()->json(['data' => []]);
    }
}
