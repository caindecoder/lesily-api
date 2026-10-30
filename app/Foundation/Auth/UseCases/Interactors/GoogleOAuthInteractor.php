<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use Exception;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\Billing\Enums\Feature;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Auth\UseCases\PendingSession;
use App\Foundation\Auth\UseCases\SignInFinisher;
use App\Foundation\Auth\Totp\LoginChallengeStore;
use App\Foundation\Auth\UseCases\DeviceLimitGuard;
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
use App\Foundation\Billing\Exceptions\FeatureUnavailableException;
use App\Foundation\Auth\Exceptions\GoogleAccountNotRegisteredException;

/**
 * Sign in with a Google ID token (UC-1-003) — never creates an account (G5). Check order:
 * token → match → a different Google account → plan → email verified → finish (GS2).
 */
class GoogleOAuthInteractor
{
    public function __construct(
        protected readonly GoogleIdTokenVerifier $verifier,
        protected readonly AccessLogRecorder $accessLog,
        protected readonly LoginChallengeStore $challenges,
        protected readonly DeviceLimitGuard $deviceLimitGuard,
        protected readonly SignInFinisher $finisher,
    ) {}

    public function execute(GoogleOAuthCommand $command): ChallengeRequired|SessionIssued
    {
        $identity = $this->verify($command->idToken);
        $user = $this->match($identity);
        $this->refuseWithoutGoogleSignInPlan($user);

        $googleSubjectToLink = $user->google_id === null ? $identity->subject : null;

        if ($googleSubjectToLink !== null) {
            $this->refuseUnverifiedEmail($user);
        }

        // A 2FA user's first link waits for the second factor (G13): it rides in the challenge.
        if ($user->two_factor_confirmed_at !== null) {
            $challengeId = $this->challenges->create($user->getKey(), $googleSubjectToLink);
            $this->accessLog->note(AccessEvent::LoginChallenged, null, $user);

            return new ChallengeRequired($challengeId, now()->addMinutes(LoginChallengeStore::TTL_MINUTES));
        }

        $this->deviceLimitGuard->enforce($user);

        return DB::transaction(fn (): SessionIssued => $this->finisher->finish(
            new PendingSession($user, $command->deviceName, googleSubjectToLink: $googleSubjectToLink),
        ));
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

    protected function match(GoogleIdentity $identity): User
    {
        $user = User::query()->where('google_id', $identity->subject)->first();

        if ($user !== null) {
            return $user;
        }

        if (! $identity->emailVerified) {
            $this->refuse(AccessEventReason::EmailNotVerified, null, new EmailNotVerifiedException);
        }

        $user = User::query()->where('email', $identity->email)->first()
            ?? $this->refuse(AccessEventReason::UnknownAccount, null, new GoogleAccountNotRegisteredException($identity->email, $identity->name));

        if ($user->google_id !== null) {
            $this->refuse(AccessEventReason::GoogleAccountMismatch, $user, GoogleAlreadyLinkedException::toAnotherGoogleAccount());
        }

        return $user;
    }

    protected function refuseWithoutGoogleSignInPlan(User $user): void
    {
        if (! $user->hasCompanyThatCanUse(Feature::GoogleOAuth)) {
            $this->refuse(AccessEventReason::PlanNotEligible, $user, FeatureUnavailableException::forFeature(Feature::GoogleOAuth));
        }
    }

    protected function refuseUnverifiedEmail(User $user): void
    {
        if ($user->email_verified_at === null) {
            $this->refuse(AccessEventReason::EmailNotVerified, $user, new EmailNotVerifiedException);
        }
    }

    protected function refuse(AccessEventReason $reason, ?User $user, Exception $refusal): never
    {
        $this->accessLog->note(AccessEvent::LoginFailed, $reason, $user);

        throw $refusal;
    }
}
