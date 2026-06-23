<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Response;
use App\Http\Controllers\Controller;

class DisableTwoFactorController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
