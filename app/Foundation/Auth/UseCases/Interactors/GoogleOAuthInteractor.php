<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Auth\UseCases\PendingSession;
use App\Foundation\Auth\UseCases\SignInFinisher;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\Auth\UseCases\Google\GoogleIdentity;
use App\Foundation\Auth\UseCases\Results\SessionIssued;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;
use App\Foundation\Auth\UseCases\Results\ChallengeRequired;
use App\Foundation\Auth\Exceptions\EmailNotVerifiedException;
use App\Foundation\Auth\UseCases\Commands\GoogleOAuthCommand;
use App\Foundation\Auth\UseCases\Google\GoogleIdTokenVerifier;
use App\Foundation\Auth\Exceptions\GoogleTokenInvalidException;
use App\Foundation\Auth\Exceptions\GoogleAlreadyLinkedException;
use App\Foundation\Auth\Exceptions\GoogleAccountNotRegisteredException;

/** Sign in with a Google ID token (UC-1-003) — never creates an account (G5). */
class GoogleOAuthInteractor
{
    public function __construct(
        protected readonly GoogleIdTokenVerifier $verifier,
        protected readonly AccessLogRecorder $accessLog,
        protected readonly SignInFinisher $finisher,
    ) {}

    public function execute(GoogleOAuthCommand $command): ChallengeRequired|SessionIssued
    {
        $identity = $this->verify($command->idToken);
        $user = $this->match($identity);

        return $this->finisher->finish(new PendingSession($user, $command->deviceName));
    }

    protected function match(GoogleIdentity $identity): User
    {
        $user = User::query()->where('google_id', $identity->subject)->first();

        if ($user === null && ! $identity->emailVerified) {
            $this->accessLog->note(AccessEvent::LoginFailed, AccessEventReason::EmailNotVerified, null);

            throw new EmailNotVerifiedException;
        }

        if ($user !== null) {
            return $user;
        }

        $user = User::query()->where('email', $identity->email)->first();

        if ($user === null) {
            $this->accessLog->note(AccessEvent::LoginFailed, AccessEventReason::UnknownAccount, null);

            throw new GoogleAccountNotRegisteredException($identity->email, $identity->name);
        }

        if ($user->google_id !== null) {
            $this->accessLog->note(AccessEvent::LoginFailed, AccessEventReason::GoogleAccountMismatch, $user);

            throw GoogleAlreadyLinkedException::toAnotherGoogleAccount();
        }

        throw new RuntimeException('Not implemented');
    }

    protected function verify(string $idToken): GoogleIdentity
    {
        try {
            return $this->verifier->verify($idToken);
        } catch (GoogleTokenInvalidException $e) {
            $this->accessLog->note(AccessEvent::LoginFailed, AccessEventReason::InvalidGoogleToken, null);

            throw $e;
        }
    }
}
