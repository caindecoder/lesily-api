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
    public function store(Request $request, LinkGoogleAccountInteractor $linkGoogleAccountInteractor): Response
    {
        $linkGoogleAccountCommand = LinkGoogleAccountCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        $linkGoogleAccountInteractor->execute($user, $linkGoogleAccountCommand);

        return response()->noContent();
    }

    public function destroy(Request $request, UnlinkGoogleAccountInteractor $unlinkGoogleAccountInteractor): Response
    {
        $unlinkGoogleAccountCommand = UnlinkGoogleAccountCommand::validateAndCreate($request);

        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken $currentPersonalAccessToken */
        $currentPersonalAccessToken = $user->currentAccessToken();

        $unlinkGoogleAccountInteractor->execute($user, $unlinkGoogleAccountCommand, $currentPersonalAccessToken->getKey());

        return response()->noContent();
    }
}
