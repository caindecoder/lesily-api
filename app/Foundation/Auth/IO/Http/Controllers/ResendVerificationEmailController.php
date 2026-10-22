<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\UseCases\Interactors\ResendVerificationEmailInteractor;

class ResendVerificationEmailController extends Controller
{
    public function __invoke(Request $request, ResendVerificationEmailInteractor $interactor): Response
    {
        /** @var User $user */
        $user = $request->user();

        $interactor->execute($user);

        return response()->noContent();
    }
}
