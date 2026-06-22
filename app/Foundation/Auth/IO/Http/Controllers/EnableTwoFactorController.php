<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EnableTwoFactorController
{
    public function __invoke(Request $request): JsonResponse
    {
        throw new \RuntimeException('Not implemented');
    }
}
