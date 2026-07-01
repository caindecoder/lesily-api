<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use Illuminate\Support\Facades\Password;
use App\Foundation\Auth\UseCases\Commands\ForgotPasswordCommand;

/**
 * Request a password-reset link (UC-1-008, forgot portion). Delegates to Laravel's password
 * broker and IGNORES the outcome: a registered email issues a single-use token + queues the
 * reset-link notification, an unregistered email silently no-ops, and either way the controller
 * returns the same 204 — so the response never reveals whether the email is registered.
 */
class ForgotPasswordInteractor
{
    public function execute(ForgotPasswordCommand $command): void
    {
        Password::broker()->sendResetLink(['email' => $command->email]);
    }
}
