<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;

class ChangePasswordController extends Controller
{
    public function __invoke(Request $request): Response
    {
        throw new RuntimeException('Not implemented');
    }
}
