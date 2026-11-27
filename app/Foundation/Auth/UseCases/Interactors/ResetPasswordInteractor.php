<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use App\Foundation\Auth\UseCases\TokenRevoker;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\AuditTrail\UseCases\Audit\UserLevelAudit;
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
    public function __construct(
        private TokenRevoker $tokenRevoker,
        private UserLevelAudit $userLevelAudit,
    ) {}

    public function execute(ResetPasswordCommand $resetPasswordCommand, RequestOrigin $requestOrigin): void
    {
        $status = DB::transaction(fn (): string => Password::broker()->reset(
            ['email' => $resetPasswordCommand->email, 'password' => $resetPasswordCommand->password, 'token' => $resetPasswordCommand->token],
            function (User $user, string $plainPassword) use ($requestOrigin): void {
                UserAggregate::retrieve($user->getKey())
                    ->resetPassword(Hash::make($plainPassword))
                    ->persist();

                $this->tokenRevoker->revokeAllForUserExcept($user, null);

                $this->userLevelAudit->record($user, AuditAction::PasswordReset, $requestOrigin);
            },
        ));

        if ($status !== Password::PASSWORD_RESET) {
            throw new InvalidResetLinkException;
        }
    }
}
