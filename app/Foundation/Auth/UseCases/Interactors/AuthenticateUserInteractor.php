<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Foundation\Auth\UseCases\Commands\LoginCommand;
use App\Foundation\Auth\Exceptions\InvalidCredentialsException;

/**
 * Verifies email + password against the projected `users` read-model and returns
 * the authenticated user, or throws InvalidCredentialsException. Token-only: no
 * aggregate is retrieved and no transaction is opened (slice-auth-login §4); the
 * controller issues the Sanctum token. A single throw for both unknown-email and
 * wrong-password keeps the failure indistinguishable by response body
 * (anti-enumeration). Timing-side anti-enumeration (equalizing the unknown-email
 * cost to a wrong-password Hash::check) is deferred to the audit/security slice.
 */
class AuthenticateUserInteractor
{
    public function execute(LoginCommand $command): User
    {
        $user = User::query()->where('email', $command->email)->first();

        if ($user === null || ! Hash::check($command->password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        return $user;
    }
}
