<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\UseCases\Commands\ResetPasswordCommand;
use App\Foundation\Auth\UseCases\Interactors\ResetPasswordInteractor;

/**
 * Thin delivery appendix (E07) for POST /api/v1/auth/reset-password — a guest setting a new
 * password via the emailed single-use token (UC-1-008). Validation lives on the Command; the
 * broker token-validate + event-sourced write + revoke-all live in the interactor; the controller
 * only shapes the 204 (an invalid / expired / used token surfaces as InvalidResetLinkException -> 422).
 */
class ResetPasswordController extends Controller
{
    public function __invoke(Request $request, ResetPasswordInteractor $interactor): Response
    {
        $interactor->execute(ResetPasswordCommand::validateAndCreate($request));

        return response()->noContent();
    }
}
