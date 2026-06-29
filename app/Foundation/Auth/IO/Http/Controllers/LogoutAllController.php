<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use RuntimeException;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\UseCases\Commands\LogoutAllCommand;

class LogoutAllController extends Controller
{
    public function __invoke(Request $request): never
    {
        LogoutAllCommand::validateAndCreate($request);

        throw new RuntimeException('Not implemented');
    }
}
