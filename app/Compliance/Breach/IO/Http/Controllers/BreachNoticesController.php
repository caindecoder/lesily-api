<?php

namespace App\Compliance\Breach\IO\Http\Controllers;

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Compliance\Breach\UseCases\Commands\BreachNoticeFilters;
use App\Compliance\Breach\IO\Http\Resources\BreachNoticeResource;
use App\Compliance\Breach\IO\Http\Resources\BreachNoticeCollection;
use App\Compliance\Breach\UseCases\Interactors\ListBreachNoticesInteractor;

class BreachNoticesController extends Controller
{
    public function index(Request $request, ListBreachNoticesInteractor $interactor): JsonResponse
    {
        $notices = $interactor->execute(BreachNoticeFilters::fromQuery($request), $request->company());

        return (new BreachNoticeCollection($notices))->response($request);
    }

    public function requestFiling(): BreachNoticeResource
    {
        throw new RuntimeException('Not implemented');
    }
}
