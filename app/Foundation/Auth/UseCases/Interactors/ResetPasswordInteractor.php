<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use App\Foundation\Auth\UseCases\TokenRevoker;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\Exceptions\InvalidResetLinkException;
use App\Foundation\Auth\UseCases\Commands\ResetPasswordCommand;

/**
 * Reset a forgotten password (UC-1-008). Laravel's password broker validates the single-use token
 * (60-min expiry) against password_reset_tokens; on a valid token it invokes the callback, deletes
 * the token and returns PASSWORD_RESET. Any other status — invalid / expired / used — collapses to
 * InvalidResetLinkException -> 422 RESET_LINK_INVALID (no token-state leak). The callback routes the
 * write through the event-sourced aggregate (not the broker's default direct save) because
 * users.password is a projected column, then revokes ALL of the user's sessions (revokeAllForUserExcept
 * with null = revoke-all — unlike a change, the guest has no current session to keep; they re-log in
 * with the new password).
 */
class ResetPasswordInteractor
{
    public function __construct(private TokenRevoker $tokenRevoker) {}

    public function execute(ResetPasswordCommand $command): void
    {
        $status = Password::broker()->reset(
            ['email' => $command->email, 'password' => $command->password, 'token' => $command->token],
            function (User $user, string $plainPassword): void {
                UserAggregate::retrieve($user->getKey())
                    ->resetPassword(Hash::make($plainPassword))
                    ->persist();

                $this->tokenRevoker->revokeAllForUserExcept($user, null);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new InvalidResetLinkException;
        }
    }
}
