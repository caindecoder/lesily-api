<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use Illuminate\Support\Facades\Password;
use App\Foundation\Auth\Exceptions\InvalidResetLinkException;
use App\Foundation\Auth\UseCases\Commands\ResetPasswordCommand;

/**
 * Reset a forgotten password (UC-1-008). Laravel's password broker validates the single-use token
 * (60-min expiry) against password_reset_tokens; on a valid token it invokes the callback, deletes
 * the token and returns PASSWORD_RESET. Any other status — invalid / expired / used — collapses to
 * InvalidResetLinkException -> 422 RESET_LINK_INVALID (no token-state leak). The callback routes the
 * write through the event-sourced aggregate (not the broker's default direct save) because
 * users.password is a projected column; session revocation follows on success.
 */
class ResetPasswordInteractor
{
    public function execute(ResetPasswordCommand $command): void
    {
        $status = Password::broker()->reset(
            ['email' => $command->email, 'password' => $command->password, 'token' => $command->token],
            fn () => null,
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new InvalidResetLinkException;
        }
    }
}
