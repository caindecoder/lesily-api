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
 * wrong-password keeps the failure indistinguishable (anti-enumeration).
 */
class AuthenticateUserInteractor
{
    /**
     * A throwaway bcrypt hash (cost 12 — matches config('hashing.bcrypt.rounds'))
     * checked against when no user matches, so a Hash::check ALWAYS runs. An
     * unknown email then costs the same as a wrong password and the two cannot be
     * told apart by response timing — the timing half of UC-1-002 anti-enumeration
     * (the body half is the single InvalidCredentialsException).
     */
    private const TIMING_SAFE_DUMMY_HASH = '$2y$12$rc/xHxvyPz3y0IfKBMF5Zenttym7cQMSCCFx.9JaiV3IuaMW2FJo2';

    public function execute(LoginCommand $command): User
    {
        $user = User::query()->where('email', $command->email)->first();

        if ($user === null) {
            // Equalize timing against the wrong-password path; result intentionally discarded.
            Hash::check($command->password, self::TIMING_SAFE_DUMMY_HASH);

            throw new InvalidCredentialsException;
        }

        if (! Hash::check($command->password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        return $user;
    }
}
