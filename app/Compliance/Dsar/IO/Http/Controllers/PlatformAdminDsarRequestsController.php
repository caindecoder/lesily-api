<?php

namespace App\Compliance\Dsar\IO\Http\Controllers;

use RuntimeException;
use App\Http\Controllers\Controller;

class PlatformAdminDsarRequestsController extends Controller
{
    public function show(string $requestId): never
    {
        throw new RuntimeException('Not implemented');
    }
}
