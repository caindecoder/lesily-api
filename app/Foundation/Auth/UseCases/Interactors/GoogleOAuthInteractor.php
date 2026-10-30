<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Auth\UseCases\PendingSession;
use App\Foundation\Auth\UseCases\SignInFinisher;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\Auth\UseCases\Google\GoogleIdentity;
use App\Foundation\Auth\UseCases\Results\SessionIssued;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;
use App\Foundation\Auth\UseCases\Results\ChallengeRequired;
use App\Foundation\Auth\UseCases\Commands\GoogleOAuthCommand;
use App\Foundation\Auth\UseCases\Google\GoogleIdTokenVerifier;
use App\Foundation\Auth\Exceptions\GoogleTokenInvalidException;

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
        $user = User::query()->where('google_id', $identity->subject)->firstOrFail();

        return $this->finisher->finish(new PendingSession($user, $command->deviceName));
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
