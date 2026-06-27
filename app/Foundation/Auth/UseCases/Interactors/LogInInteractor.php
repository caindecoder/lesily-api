<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Foundation\Auth\UseCases\SessionIssuer;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\Auth\UseCases\Commands\LoginCommand;
use App\Foundation\Auth\UseCases\Results\SessionIssued;
use App\Foundation\Auth\UseCases\Results\ChallengeRequired;
use App\Foundation\Auth\Exceptions\InvalidCredentialsException;

/**
 * The "Log In" use case (UC-1-002). Verifies email + password against the projected `users`
 * read-model, then either opens a 2FA challenge (enrolled users) or mints a session — and
 * returns a sealed LoginOutcome the controller maps to 202/200. Token-only: no aggregate is
 * retrieved and no transaction is opened (a login does not change the user's business state).
 * The 2FA gate, challenge creation, and token mint were relocated here out of the controller
 * (session 090 token-lifecycle refactor — E07 Dependency Rule / SRP). A single throw for both
 * unknown-email and wrong-password keeps the failure indistinguishable by response body
 * (anti-enumeration); timing-side anti-enumeration is deferred to the audit/security slice.
 */
class LogInInteractor
{
    public function __construct(
        private readonly LoginChallengeStore $challenges,
        private readonly SessionIssuer $sessionIssuer,
    ) {}

    public function execute(LoginCommand $command): ChallengeRequired|SessionIssued
    {
        $user = $this->authenticate($command);

        // 2FA gate (UC-1-002 alt-4a): an enrolled user gets a transient challenge, not a
        // token — login is suspended at "awaiting-2fa" until /2fa/verify. Non-enrolled users
        // get a session straight away (the existing 200 path, forward-compatible).
        if ($user->two_factor_confirmed_at !== null) {
            return new ChallengeRequired(
                $this->challenges->create($user->id),
                now()->addMinutes(LoginChallengeStore::TTL_MINUTES),
            );
        }

        $user->last_logged_at = now();
        $user->save();

        return new SessionIssued($this->sessionIssuer->issue($user, $command->deviceName), $user);
    }

    private function authenticate(LoginCommand $command): User
    {
        $user = User::query()->where('email', $command->email)->first();

        if ($user === null || ! Hash::check($command->password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        return $user;
    }
}
