<?php

namespace App\Compliance\Breach\IO\Http\Controllers;

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Compliance\Breach\UseCases\Commands\RecordBreachCommand;

class PlatformAdminBreachIncidentsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        RecordBreachCommand::validateAndCreate($request);

        throw new RuntimeException('Not implemented');
    }
}
