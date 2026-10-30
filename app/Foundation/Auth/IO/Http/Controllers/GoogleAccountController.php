<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use Laravel\Sanctum\PersonalAccessToken;
use App\Foundation\Auth\UseCases\Commands\LinkGoogleAccountCommand;
use App\Foundation\Auth\UseCases\Commands\UnlinkGoogleAccountCommand;
use App\Foundation\Auth\UseCases\Interactors\LinkGoogleAccountInteractor;
use App\Foundation\Auth\UseCases\Interactors\UnlinkGoogleAccountInteractor;

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

    public function destroy(Request $request, UnlinkGoogleAccountInteractor $interactor): Response
    {
        $command = UnlinkGoogleAccountCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken $current */
        $current = $user->currentAccessToken();

        $interactor->execute($user, $command, $current->getKey());

        return response()->noContent();
    }
}
