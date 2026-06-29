<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use RuntimeException;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class LogoutAllController extends Controller
{
    public function __invoke(Request $request): never
    {
        throw new RuntimeException('Not implemented');
    }
}
