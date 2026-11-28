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
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\UseCases\Results\ChallengeRequired;
use App\Foundation\Auth\Exceptions\EmailNotVerifiedException;
use App\Foundation\Auth\UseCases\Commands\GoogleOAuthCommand;
use App\Foundation\Auth\UseCases\Google\GoogleIdTokenVerifier;
use App\Foundation\Auth\Exceptions\GoogleTokenInvalidException;
use App\Foundation\Auth\Exceptions\GoogleAlreadyLinkedException;
use App\Foundation\Auth\Exceptions\GoogleSignInUnlinkedException;
use App\Foundation\Billing\Exceptions\FeatureUnavailableException;
use App\Foundation\Auth\Exceptions\GoogleAccountNotRegisteredException;

/**
 * Sign in with a Google ID token (UC-1-003) — never creates an account (G5). Check order:
 * token → match → a different Google account → plan → email verified → finish (GS2).
 */
class GoogleOAuthInteractor
{
    public function __construct(
        protected readonly GoogleIdTokenVerifier $googleIdTokenVerifier,
        protected readonly AccessLogRecorder $accessLogRecorder,
        protected readonly LoginChallengeStore $loginChallengeStore,
        protected readonly DeviceLimitGuard $deviceLimitGuard,
        protected readonly SignInFinisher $signInFinisher,
    ) {}

    public function execute(GoogleOAuthCommand $googleOAuthCommand): ChallengeRequired|SessionIssued
    {
        $googleIdentity = $this->verify($googleOAuthCommand->idToken);
        $user = $this->match($googleIdentity);
        $this->refuseWithoutGoogleSignInPlan($user);

        $googleSubjectToLink = $user->google_id === null ? $googleIdentity->subject : null;

        if ($googleSubjectToLink !== null) {
            $this->refuseUnverifiedEmail($user);
        }

        // A 2FA user's first link waits for the second factor (G13): it rides in the challenge.
        if ($user->two_factor_confirmed_at !== null) {
            $challengeId = $this->loginChallengeStore->create($user->getKey(), $googleSubjectToLink);
            $this->accessLogRecorder->note(AccessEvent::LoginChallenged, null, $user);

            return new ChallengeRequired($challengeId, now()->addMinutes(LoginChallengeStore::TTL_MINUTES));
        }

        $this->deviceLimitGuard->enforce($user);

        return DB::transaction(function () use ($user, $googleOAuthCommand, $googleSubjectToLink): SessionIssued {
            if ($googleSubjectToLink !== null) {
                UserAggregate::retrieve($user->getKey())->linkGoogleOAuth($googleSubjectToLink)->persist();
            }

            return $this->signInFinisher->finish(new PendingSession($user, $googleOAuthCommand->deviceName));
        });
    }

    protected function verify(string $idToken): GoogleIdentity
    {
        try {
            return $this->googleIdTokenVerifier->verify($idToken);
        } catch (GoogleTokenInvalidException $googleTokenInvalidException) {
            $this->accessLogRecorder->note(AccessEvent::LoginFailed, AccessEventReason::InvalidGoogleToken, null);

            throw $googleTokenInvalidException;
        }
    }

    protected function match(GoogleIdentity $googleIdentity): User
    {
        $user = User::query()->where('google_id', $googleIdentity->subject)->first();

        if ($user !== null) {
            return $user;
        }

        if (! $googleIdentity->emailVerified) {
            $this->refuse(AccessEventReason::EmailNotVerified, null, new EmailNotVerifiedException);
        }

        $user = User::query()->where('email', $googleIdentity->email)->first()
            ?? $this->refuse(AccessEventReason::UnknownAccount, null, new GoogleAccountNotRegisteredException($googleIdentity->email, $googleIdentity->name));

        if ($user->google_id !== null) {
            $this->refuse(AccessEventReason::GoogleAccountMismatch, $user, GoogleAlreadyLinkedException::toAnotherGoogleAccount());
        }

        if ($user->google_unlinked_at !== null) {
            $this->refuse(AccessEventReason::GoogleUnlinked, $user, new GoogleSignInUnlinkedException);
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

    protected function refuse(AccessEventReason $accessEventReason, ?User $user, Exception $exception): never
    {
        $this->accessLogRecorder->note(AccessEvent::LoginFailed, $accessEventReason, $user);

        throw $exception;
    }
}
