<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Auth\UseCases\SessionIssuer;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\Auth\UseCases\DeviceLimitGuard;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\Auth\UseCases\Commands\LoginCommand;
use App\Foundation\Auth\UseCases\Results\SessionIssued;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;
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
        private readonly DeviceLimitGuard $deviceLimitGuard,
        private readonly AccessLogRecorder $accessLog,
    ) {}

    public function execute(LoginCommand $command): ChallengeRequired|SessionIssued
    {
        $user = $this->authenticate($command);

        // 2FA gate (UC-1-002 alt-4a): an enrolled user gets a transient challenge, not a
        // token — login is suspended at "awaiting-2fa" until /2fa/verify. Non-enrolled users
        // get a session straight away (the existing 200 path, forward-compatible).
        if ($user->two_factor_confirmed_at !== null) {
            $challengeId = $this->challenges->create($user->getKey());
            $this->accessLog->note(AccessEvent::LoginChallenged, null, $user);

            return new ChallengeRequired($challengeId, now()->addMinutes(LoginChallengeStore::TTL_MINUTES));
        }

        // A non-2FA user mints here, so the device cap is enforced here (UC-1-002 alt-5a)
        // before any side effect — a capped account is rejected without a token or a
        // bumped last_logged_at. 2FA users mint at /2fa/verify, where the cap is enforced too.
        $this->deviceLimitGuard->enforce($user);

        $user->last_logged_at = now();
        $user->save();
        $session = $this->sessionIssuer->issue($user, $command->deviceName);
        $this->accessLog->note(AccessEvent::LoginSucceeded, null, $user);

        return new SessionIssued($session, $user);
    }

    private function authenticate(LoginCommand $command): User
    {
        $user = User::query()->where('email', $command->email)->first();
        $failure = $this->failureReason($command, $user);

        if ($failure !== null) {
            $this->accessLog->note(AccessEvent::LoginFailed, $failure, $user);

            throw new InvalidCredentialsException;
        }

        /** @var User $user */
        return $user;
    }

    /** The access log tells the two apart; the client never can (one throw, one message). */
    private function failureReason(LoginCommand $command, ?User $user): ?AccessEventReason
    {
        return match (true) {
            $user === null => AccessEventReason::UnknownAccount,
            ! Hash::check($command->password, $user->password) => AccessEventReason::WrongPassword,
            default => null,
        };
    }
}
