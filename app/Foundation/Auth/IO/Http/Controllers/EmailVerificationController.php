<?php

namespace App\Foundation\Auth\IO\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Foundation\Auth\UseCases\Interactors\VerifyEmailInteractor;

/**
 * Thin delivery appendix (E07) for the signed email-verification link
 * (UC-1-001 alt-6a). The `signed` middleware is the credential — an invalid or
 * expired signature never reaches this action, and the signature already covers
 * {id} and {hash}, so the action only hands the verified id to the use case.
 *
 * NOTE: {hash} is intentionally not re-checked against the current email here —
 * the signature already binds it. A hash_equals(sha1(email), {hash}) guard
 * becomes necessary only once an email-change UC exists (to invalidate a link
 * issued for a since-changed address); deferred until then.
 */
class EmailVerificationController extends Controller
{
    public function __invoke(string $id, VerifyEmailInteractor $interactor): JsonResponse
    {
        $interactor->execute($id);

        return response()->json(['message' => 'Email verified.']);
    }
}
