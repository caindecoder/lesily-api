<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;

class DsarRequestsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        SubmitDsarRequestCommand::validateAndCreate([
            'subject_user_id' => $request->input('subject_user_id'),
        ]);

        return new JsonResponse(status: Response::HTTP_CREATED);
    }
}
