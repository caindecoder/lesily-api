<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\IO\Http\Resources\SessionCollection;
use App\Foundation\Auth\UseCases\Interactors\ListSessionsInteractor;

/**
 * Thin delivery appendix (E07) for GET /api/v1/me/sessions — the authenticated
 * user's own active login sessions (UC-1-010). Delegates the "which sessions"
 * read to ListSessionsInteractor; SessionCollection formats them.
 */
class ViewSessionsController extends Controller
{
    public function __invoke(Request $request, ListSessionsInteractor $interactor): SessionCollection
    {
        /** @var User $user */
        $user = $request->user();

        return new SessionCollection($interactor->execute($user));
    }
}
