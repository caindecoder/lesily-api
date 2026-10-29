<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\UseCases\Commands\LinkGoogleAccountCommand;
use App\Foundation\Auth\UseCases\Interactors\LinkGoogleAccountInteractor;

class GoogleAccountController extends Controller
{
    public function store(Request $request, LinkGoogleAccountInteractor $interactor): Response
    {
        $command = LinkGoogleAccountCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        $interactor->execute($user, $command);

        return response()->noContent();
    }
}
