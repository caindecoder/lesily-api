<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\UseCases\Commands\ForgotPasswordCommand;
use App\Foundation\Auth\UseCases\Interactors\ForgotPasswordInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/forgot-password — a guest requesting a
 * password-reset link (UC-1-008). Validation lives on the Command, the broker call in the
 * interactor; the controller always shapes an enumeration-safe 204 — the interactor never
 * signals whether the email was registered.
 */
class ForgotPasswordController extends Controller
{
    public function __invoke(Request $request, ForgotPasswordInteractor $interactor): Response
    {
        $interactor->execute(ForgotPasswordCommand::validateAndCreate($request));

        return response()->noContent();
    }
}
