<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Controllers;

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * The company's ownership-transfer resource — request (UC-1-028, SM-1-05 T1).
 * Later slice-#11 tasks add the reads (UC-1-125/126) and withdraw
 * (UC-1-114/115) actions.
 */
class OwnershipTransfersController
{
    public function store(Request $request): JsonResponse
    {
        throw new RuntimeException('Not implemented');
    }
}
