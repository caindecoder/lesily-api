<?php

namespace App\Companies\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * The company resource — details view (UC-1-032); update (UC-1-033) joins at
 * the next slice task. Repository-direct read (cd-companies §5): AuthorizeCompany
 * resolves + membership-checks the company upstream.
 */
class CompaniesController
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $request->company()->getKey(),
            ],
        ]);
    }
}
