<?php

namespace App\Consent\IO\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Consent\UseCases\Interactors\AcceptConsentInteractor;

class AcceptConsentController extends Controller
{
    public function __invoke(Request $request, AcceptConsentInteractor $interactor): Response
    {
        /** @var User $user */
        $user = $request->user();

        $interactor->execute($request->company(), $user, RequestOrigin::fromRequest($request));

        return response()->noContent();
    }
}
